<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic Contributors. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.org
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CoreBundle\Helper;


use Mautic\CoreBundle\Exception as MauticException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Mautic\CoreBundle\Templating\Helper\ThemeHelper as TemplatingThemeHelper;

class ThemeHelper
{
    /**
     * @var PathsHelper
     */
    private $pathsHelper;

    /**
     * @var array|mixed
     */
    private $themes = array();

    /**
     * @var array
     */
    private $themesInfo = array();

    /**
     * @var array
     */
    private $steps = array();

    /**
     * @var string
     */
    private $defaultTheme;

    /**
     * @var TemplatingThemeHelper[]
     */
    private $themeHelpers = array();

    /**
     * ThemeHelper constructor.
     * 
     * @param PathsHelper $pathsHelper
     */
    public function __construct(PathsHelper $pathsHelper, TemplatingHelper $templatingHelper)
    {
        $this->pathsHelper = $pathsHelper;
        $this->templatingHelper = $templatingHelper;
    }

    /**
     * @param string $defaultTheme
     */
    public function setDefaultTheme($defaultTheme)
    {
        $this->defaultTheme = $defaultTheme;
    }

    /**
     * @param string $themeName
     * 
     * @return ThemeHelper
     */
    public function createThemeHelper($themeName)
    {
        if ($themeName === 'current') {
            $themeName = $this->defaultTheme;
        }

        $themeHelper = new TemplatingThemeHelper($this->pathsHelper, $themeName);
        
        return $themeHelper;
    }

    /**
     * @param $newName
     * 
     * @return string
     */
    private function getDirectoryName($newName)
    {
        return InputHelper::alphanum($newName, true);
    }

    /**
     * @param $theme
     *
     * @return boolean
     */
    public function exists($theme)
    {
        $root    = $this->pathsHelper->getSystemPath('themes', true) . '/';
        $dirName = $this->getDirectoryName($theme);
        $fs      = new Filesystem();

        return $fs->exists($root.$dirName);
    }

    /**
     * @param $theme
     * @param $newName
     *
     * @throws MauticException\FileExistsException
     * @throws MauticException\FileNotFoundException
     */
    public function copy($theme, $newName)
    {
        $root      = $this->pathsHelper->getSystemPath('themes', true) . '/';
        $themes    = $this->getInstalledThemes();

        //check to make sure the theme exists
        if (!isset($themes[$theme])) {
            throw new MauticException\FileNotFoundException($theme . ' not found!');
        }

        $dirName = $this->getDirectoryName($newName);

        $fs = new Filesystem();

        if ($fs->exists($root . $dirName)) {
            throw new MauticException\FileExistsException("$dirName already exists");
        }

        $fs->mirror($root . $theme, $root . $dirName);

        $this->updateConfig($root . $dirName, $newName);
    }

    /**
     * @param $theme
     * @param $newName
     *
     * @throws MauticException\FileNotFoundException
     * @throws MauticException\FileExistsException
     */
    public function rename($theme, $newName)
    {
        $root      = $this->pathsHelper->getSystemPath('themes', true) . '/';
        $themes    = $this->getInstalledThemes();

        //check to make sure the theme exists
        if (!isset($themes[$theme])) {
            throw new MauticException\FileNotFoundException($theme . ' not found!');
        }

        $dirName = $this->getDirectoryName($newName);

        $fs = new Filesystem();

        if ($fs->exists($root . $dirName)) {
            throw new MauticException\FileExistsException("$dirName already exists");
        }

        $fs->rename($root . $theme, $root . $dirName);

        $this->updateConfig($root . $theme, $dirName);
    }

    /**
     * @param $theme
     *
     * @throws MauticException\FileNotFoundException
     */
    public function delete($theme)
    {
        $root      = $this->pathsHelper->getSystemPath('themes', true) . '/';
        $themes    = $this->getInstalledThemes();

        //check to make sure the theme exists
        if (!isset($themes[$theme])) {
            throw new MauticException\FileNotFoundException($theme . ' not found!');
        }

        $fs = new Filesystem();
        $fs->remove($root.$theme);
    }

    /**
     * Updates the theme configuration and converts
     * it to json if still using php array
     *
     * @param $themePath
     * @param $newName
     */
    private function updateConfig($themePath, $newName)
    {
        if (file_exists($themePath . '/config.json')) {
            $config = json_decode(file_get_contents($themePath . '/config.json'), true);
        }

        $config['name'] = $newName;

        file_put_contents($themePath . '/config.json', json_encode($config));
    }

    /**
     * Fetches the optional settings from the defined steps.
     *
     * @return array
     */
    public function getOptionalSettings()
    {
        $minors = array();

        foreach ($this->steps as $step) {
            foreach ($step->checkOptionalSettings() as $minor) {
                $minors[] = $minor;
            }
        }

        return $minors;
    }

    /**
     * @param string $template
     *
     * @return string The logical name for the template
     */
    public function checkForTwigTemplate($template)
    {
        $parser = $this->templatingHelper->getTemplateNameParser();
        $templating = $this->templatingHelper->getTemplating();

        $template = $parser->parse($template);

        $twigTemplate = clone $template;
        $twigTemplate->set('engine', 'twig');

        if ($templating->exists($twigTemplate)) {
            return $twigTemplate->getLogicalName();
        }

        return $template->getLogicalName();
    }

    /**
     * @param string $specificFeature
     * @param boolean $extended returns extended information about the themes
     * 
     * @return mixed
     */
    public function getInstalledThemes($specificFeature = 'all', $extended = false)
    {
        if (empty($this->themes[$specificFeature])) {
            $dir = $this->pathsHelper->getSystemPath('themes', true);
            $addTheme = false;

            $finder = new Finder();
            $finder->directories()->depth('0')->ignoreDotFiles(true)->in($dir);

            $this->themes[$specificFeature] = array();
            $this->themesInfo[$specificFeature] = array();
            foreach ($finder as $theme) {
                if (file_exists($theme->getRealPath().'/config.json')) {
                    $config = json_decode(file_get_contents($theme->getRealPath() . '/config.json'), true);
                } else {
                    continue;
                }

                if ($specificFeature != 'all') {
                    if (isset($config['features']) && in_array($specificFeature, $config['features'])) {
                        $addTheme = true;
                    }
                } else {
                    $addTheme = true;
                }

                if ($addTheme) {
                    $this->themes[$specificFeature][$theme->getBasename()] = $config['name'];
                    $this->themesInfo[$specificFeature][$theme->getBasename()] = array();
                    $this->themesInfo[$specificFeature][$theme->getBasename()]['name'] = $config['name'];
                    $this->themesInfo[$specificFeature][$theme->getBasename()]['key'] = $theme->getBasename();
                    $this->themesInfo[$specificFeature][$theme->getBasename()]['dir'] = $theme->getRealPath();
                    $this->themesInfo[$specificFeature][$theme->getBasename()]['config'] = $config;
                }
            }
        }

        if ($extended) {
            return $this->themesInfo[$specificFeature];
        } else {
            return $this->themes[$specificFeature];
        }
    }

    /**
     * @param string $theme
     * @param bool $throwException
     * 
     * @return TemplatingThemeHelper
     * 
     * @throws MauticException\FileNotFoundException
     * @throws MauticException\BadConfigurationException
     */
    public function getTheme($theme = 'current', $throwException = false)
    {
        if (empty($this->themeHelpers[$theme])) {
            try {
                $this->themeHelpers[$theme] = $this->createThemeHelper($theme);
            } catch (MauticException\FileNotFoundException $e) {
                if (! $throwException) {
                    // theme wasn't found so just use the first available
                    $themes = $this->getInstalledThemes();

                    foreach ($themes as $installedTheme => $name) {
                        try {
                            if (isset($this->themeHelpers[$installedTheme])) {
                                // theme found so return it
                                return $this->themeHelpers[$installedTheme];
                            } else {
                                $this->themeHelpers[$installedTheme] = $this->createThemeHelper($installedTheme);
                                // found so use this theme
                                $theme = $installedTheme;
                                $found = true;
                                break;
                            }
                        } catch (MauticException\FileNotFoundException $e) {
                            continue;
                        }
                    }
                }

                if (empty($found)) {
                    // if we get to this point then no template was found so throw an exception regardless
                    throw $e;
                }
            }
        }

        return $this->themeHelpers[$theme];
    }

    /**
     * Install a theme from a zip package
     *
     * @param string $zipFile path
     * 
     * @return boolean
     * 
     * @throws MauticException\FileNotFoundException
     * @throws Exception
     */
    public function install($zipFile) {

        if (file_exists($zipFile) === false) {
            throw new MauticException\FileNotFoundException();
        }

        if (class_exists('ZipArchive') === false) {
            throw new \Exception('mautic.core.ziparchive.not.installed');
        }

        $zipper  = new \ZipArchive();
        $archive = $zipper->open($zipFile);

        if ($archive !== true) {
            // Get the exact error
            switch ($archive) {
                case \ZipArchive::ER_EXISTS:
                    $error = 'mautic.core.update.archive_file_exists';
                    break;
                case \ZipArchive::ER_INCONS:
                case \ZipArchive::ER_INVAL:
                case \ZipArchive::ER_MEMORY:
                    $error = 'mautic.core.update.archive_zip_corrupt';
                    break;
                case \ZipArchive::ER_NOENT:
                    $error = 'mautic.core.update.archive_no_such_file';
                    break;
                case \ZipArchive::ER_NOZIP:
                    $error = 'mautic.core.update.archive_not_valid_zip';
                    break;
                case \ZipArchive::ER_READ:
                case \ZipArchive::ER_SEEK:
                case \ZipArchive::ER_OPEN:
                default:
                    $error = 'mautic.core.update.archive_could_not_open';
                    break;
            }

            throw new \Exception($error);
        } else {
            // Extract the archive file now
            if (!$zipper->extractTo($this->pathsHelper->getSystemPath('themes', true))) {
                throw new \Exception('mautic.core.update.error_extracting_package');
            } else {
                $zipper->close();
                unlink($zipFile);
                return true;
            }
        }
    }
}
