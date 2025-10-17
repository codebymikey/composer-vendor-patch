<?php

namespace Codebymikey\ComposerVendorPatch;

use Codebymikey\ComposerVendorPatch\Plugin\GenerateVendorPatches;
use Composer\Composer;
use Composer\Factory;
use Composer\IO\ConsoleIO;
use Composer\Json\JsonFile;
use Composer\Command\BaseCommand;
use Composer\Package\BasePackage;
use React\Promise\PromiseInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Composer\Package\PackageInterface;
use Symfony\Component\Process\ExecutableFinder;

class GenerateVendorPatchesCommand extends BaseCommand {

  const COMMAND_NAME = 'generate-vendor-patches';

  protected ?string $cacheDir = NULL;

  protected function configure() {
    $this
      ->setName(self::COMMAND_NAME)
      ->setDescription('Generate patch files for modified vendor packages')
      ->addArgument('packages', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Name of the packages to patch')
      ->addOption('add-patch', NULL, InputOption::VALUE_NONE, 'Add the patch entries to composer.json for cweagans/composer-patches');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $composer = $this->requireComposer();
    $io = $this->getIO();
    $packageNames = $input->getArgument('packages');
    $addPatch = $input->getOption('add-patch');
    $fileSystem = new Filesystem();
    $repo = $composer->getRepositoryManager()->getLocalRepository();

    try {
      $packages = $this->getPackages($composer, $packageNames);
    } catch (\RuntimeException $e) {
      $output->writeln("<error>{$e->getMessage()}</error>");
      return 1;
    }

    [
      $downloadPromises,
      $pristinePaths,
    ] = $this->downloadPackages($composer, $packages, $output);
    $this->waitOnPromises($composer, $downloadPromises);
    $installationManager = $composer->getInstallationManager();
    foreach ($packages as $package) {
      // Get configuration from composer.json "extra"
      $config = $this->getConfig($package);
      $patchesDir = $this->inferFileFromPackage($package, $config['patch-dir']);
      $patchFormat = $this->inferFileFromPackage($package, $config['patch-format']);

      if (!is_dir($patchesDir)) {
        mkdir($patchesDir, 0777, TRUE);
      }
      $patchFile = sprintf(
        "%s/%s",
        rtrim($patchesDir, '/'),
        $patchFormat
      );

      $pristinePath = $pristinePaths[$package->getName()];

      $installPath = $installationManager->getInstallPath($package);
      if ($io->isDebug()) {
        $output->writeln("<info>Pristine package downloaded to <comment>{$pristinePath}</comment></info>");
      }

      // Generate patch between the directories.
      [
        $process,
        $diff,
      ] = $this->runPackageDiff($pristinePath, $installPath, $config['exclude']);

      if (!$process->isSuccessful() && $process->getExitCode() !== 1) {
        $output->writeln("<error>Error running diff command: {$process->getErrorOutput()}</error>");
        return 1;
      }
      if (!$diff) {
        $output->writeln("<warning>Empty patch file was generated for package {$package->getName()}</warning>");
      }
      file_put_contents($patchFile, $diff);
      $output->writeln("<info>Patch generated: <comment>{$patchFile}</comment></info>");

      // Optionally update composer.json to add patch entry
      if ($addPatch) {
        $this->addPatchToComposerJson($package->getName(), $patchFile, $output);
      }
    }

    // Attempt to remove the directory post diff to save disk space.
    $fileSystem->remove($pristinePaths);

    if ($io->isVerbose()) {
      $output->writeln('<info>You may now apply this patch using a Composer patch plugin or manually.</info>');
    }
    return 0;
  }

  /**
   * Returns list of packages to differentiate.
   *
   * @return BasePackage[]
   */
  protected function getPackages(Composer $composer, array $packageNames): array {
    $repo = $composer->getRepositoryManager()->getLocalRepository();

    $packages = [];
    foreach ($repo->getPackages() as $pkg) {
      foreach ($packageNames as $i => $packageName) {
        if ($pkg->getName() === $packageName) {
          $packages[] = $pkg;
          unset($packageNames[$i]);
        }
      }
      if (empty($packageNames)) {
        break;
      }
    }

    if (!empty($packageNames)) {
      throw new \RuntimeException(sprintf('Package %s not found.', implode(', ', $packageNames)));
    }

    return $packages;
  }

  protected function runPackageDiff($pristinePath, $installPath, array $excludePatterns) {
    $differ = NULL;
    $output = NULL;
    $callback = NULL;

    $finder = new ExecutableFinder();
    // Using 'diff' isn't as good as 'git diff' especially for binaries.
    foreach (['git'/*, 'diff'*/] as $executable) {
      if ($finder->find($executable)) {
        $differ = $executable;
        break;
      }
    }

    if (empty($differ)) {
      throw new \RuntimeException("The '$differ' executable could not be found.");
    }

    $pristinePath = realpath($pristinePath);
    $installPath = realpath($installPath);

    if ($differ === 'git') {
      $command = [
        'git',
        'diff',
        '--no-index',
        // '--cached',
        '--no-ext-diff',
        '--binary',
        '--no-color',
        '--color=never',
        '--ignore-space-at-eol',
        '--src-prefix=a/',
        '--dst-prefix=b/',
      ];
      // @todo exclude certain files.
    }
    else {
      $command = [
        'diff',
        '--recursive',
        '--new-file', // Treat absent files as empty.
        '--binary',
        '--unified',
        '--color=never',
        '--ignore-trailing-space',
      ];
      foreach ($excludePatterns as $excludePattern) {
        $command[] = "--exclude=$excludePattern";
      }
      // @todo pick up .gitignore entries?
    }

    array_push($command, $pristinePath, $installPath);

    $escapedPristinePath = preg_quote($pristinePath, '/');
    $escapedInstallPath = preg_quote($installPath, '/');
    $output = '';
    // Convert the output to a git diff like format.
    // The alternative is to symlink a and b, then diff that directory.
    $callback = function ($type, $data) use (&$output, $differ, $escapedPristinePath, $escapedInstallPath) {
      if ($type === Process::OUT) {
        if ($differ === 'diff') {
          $data = preg_replace('/^diff .*?--ignore-trailing-space/m', 'diff --git', $data);
          // Remove the timestamp.
          $data = preg_replace("{^(\+\+\+.+|---.+)\t.*}m", '\\1', $data);
        }

        // Make the files path-relative.
        $data = preg_replace("{^(---\s+|diff.+?)(a?)($escapedPristinePath|$escapedInstallPath)}m", '\\1a', $data);
        $data = preg_replace("{^(\+\+\+\s+|diff.+?)(b?)($escapedInstallPath|$escapedInstallPath)}m", '\\1b', $data);

        $output .= $data;
      }
    };

    $process = new Process($command);
    $process->run($callback);

    $output = $output ?? $process->getOutput();

    return [$process, $output];
  }

  protected function downloadPackages(Composer $composer, array $packages, OutputInterface $output) {
    $downloadPromises = [];
    $installationManager = $composer->getInstallationManager();
    $io = $this->getIO();
    $installDirs = [];
    foreach ($packages as $package) {
      $installPath = $installationManager->getInstallPath($package);

      if (!is_dir($installPath)) {
        throw new \RuntimeException("Install path not found: {$installPath}. Please make sure its installed.");
      }

      if ($io->isDebug()) {
        $output->writeln("Found installed path: <info>{$installPath}</info>");
      }

      $packageName = $package->getName();

      // Download the original package to a temp folder using DownloadManager
      $output->writeln(sprintf("<info>Downloading a pristine copy of the <comment>%s</comment> package to diff against...</info>", $packageName));
      $pristinePath = $this->getCacheDir($composer) . '/' . $this->inferFileFromPackage($package, '{vendor}__{name}-{version}');
      if (!is_dir($pristinePath)) {
        mkdir($pristinePath, 0777, TRUE);
      }

      $installDirs[$packageName] = $pristinePath;
      $downloadManager = $composer->getDownloadManager();

      // Create a clone of the package with a different install path
      $pristinePackage = $this->clonePackage($package);

      // DownloadManager will handle extraction and everything
      $downloadPromise = $downloadManager->download($pristinePackage, $pristinePath);
      if ($downloadPromise instanceof PromiseInterface) {
        $downloadPromises[$packageName] = $downloadPromise
          // Prepare for install.
          ->then(static function () use ($downloadManager, $pristinePackage, $pristinePath) {
            return $downloadManager->prepare('install', $pristinePackage, $pristinePath);
          })
          ->then(static function () use ($downloadManager, $pristinePackage, $pristinePath) {
            return $downloadManager->install($pristinePackage, $pristinePath);
          })
          // Clean up after any download errors.
          ->then(
            static function () use ($downloadManager, $pristinePackage, $pristinePath) {
              return $downloadManager->cleanup('install', $pristinePackage, $pristinePath);
            },
            static function ($e) use ($composer, $downloadManager, $pristinePackage, $pristinePath) {
              // Clean up after any errors.
              $composer->getLoop()
                ->wait([
                  $downloadManager->cleanup('install', $pristinePackage, $pristinePath),
                ]);
              throw $e;
            });
      }
    }
    return [$downloadPromises, $installDirs];
  }

  protected function inferFileFromPackage(PackageInterface $package, $template) {
    $prettyName = $package->getPrettyName();
    if (str_contains($prettyName, '/')) {
      [$vendor, $name] = explode('/', $prettyName);
    }
    else {
      $vendor = '';
      $name = $prettyName;
    }
    $inflections = [
      '{vendor}' => preg_replace('{^\W}', '', $vendor),
      '{name}' => preg_replace('{^\W}', '', $name),
      '{type}' => preg_replace('{^\W}', '', $package->getType()),
      '{sourceReference}' => preg_replace('{^\W}', '', (string) $package->getSourceReference()),
      '{distReference}' => preg_replace('{^\W}', '', (string) $package->getDistReference()),
      '{distSha1Checksum}' => preg_replace('{^\W}', '', (string) $package->getDistSha1Checksum()),
      '{version}' => preg_replace('{^\W}', '', $package->getPrettyVersion()),
    ];

    return str_replace(array_keys($inflections), $inflections, $template);
  }

  /**
   * Wait synchronously for an array of promises to resolve.
   *
   * @param array $promises
   *   Promises to await.
   */
  protected function waitOnPromises(Composer $composer, array $promises) {
    $io = $this->getIO();
    $progress = NULL;
    if ($io instanceof ConsoleIO && !$io->isDebug() && count($promises) > 2 && !getenv('COMPOSER_DISABLE_PROGRESS_BAR')) {
      // Disable progress bar by setting COMPOSER_DISABLE_PROGRESS_BAR=1 as we
      // are unable to read composer's "--no-progress" option easily from here
      // without introducing extra complexity with the PluginEvents::COMMAND
      // event.
      $progress = $io->getProgressBar();
    }
    $composer->getLoop()->wait($promises, $progress);
    if ($progress) {
      $progress->clear();
    }
  }

  /**
   * Clone the package, but change install path to avoid conflicts.
   *
   * This ensures we can use DownloadManager without affecting the actual
   * install since the install path is based off the package object reference.
   */
  private function clonePackage(PackageInterface $package): PackageInterface {
    $clone = clone $package;
    return $clone;
  }

  /**
   * Get config from composer.json extra.
   */
  private function getConfig(PackageInterface $package): array {
    $composerFile = $this->getComposerJsonFile();
    $patchDir = 'patches';
    $patchFormat = '{vendor}__{name}.diff';
    $excludePatterns = [];
    if (file_exists($composerFile)) {
      $jsonFile = new JsonFile($composerFile);
      $json = $jsonFile->read();
      $packageName = $package->getName();
      $configuration_name = GenerateVendorPatches::PLUGIN_NAME;
      if (isset($json['extra'][$configuration_name])) {
        $configs = $json['extra'][$configuration_name];
        if (isset($configs['patch-dir'][$packageName])) {
          $patchDir = $configs['patch-dir'][$packageName];
        }
        else {
          if (isset($configs['patch-dir']) && is_string($configs['patch-dir'])) {
            $patchDir = $configs['patch-dir'];
          }
        }
        if (isset($configs['patch-format'][$packageName])) {
          $patchFormat = $configs['patch-format'][$packageName];
        }
        else {
          if (isset($configs['patch-format']) && is_string($configs['patch-format'])) {
            $patchFormat = $configs['patch-format'];
          }
        }
        if (isset($configs['exclude'][$packageName])) {
          $excludePatterns = $configs['exclude'][$packageName];
        }
      }
    }
    return [
      'patch-dir' => $patchDir,
      'patch-format' => $patchFormat,
      'exclude' => $excludePatterns,
    ];
  }

  /**
   * Add patch entry to composer.json for cweagans/composer-patches
   */
  private function addPatchToComposerJson(string $packageName, string $patchFile, OutputInterface $output): void {
    $composerFile = $this->getComposerJsonFile();
    if (!file_exists($composerFile)) {
      $output->writeln("<error>Cannot find {$composerFile} in the current directory.</error>");
      return;
    }
    $jsonFile = new JsonFile($composerFile);
    $json = $jsonFile->read();

    if (!isset($json['extra'])) {
      $json['extra'] = [];
    }
    if (!isset($json['extra']['patches'])) {
      $json['extra']['patches'] = [];
    }
    if (!isset($json['extra']['patches'][$packageName])) {
      $json['extra']['patches'][$packageName] = [];
    }

    $patchRelPath = $this->getRelativePath(getcwd(), $patchFile);

    // Only add if not already present
    if (!in_array($patchRelPath, $json['extra']['patches'][$packageName])) {
      $json['extra']['patches'][$packageName]['Autogenerated patch by ' . GenerateVendorPatches::PLUGIN_NAME] = $patchRelPath;
      $jsonFile->write($json);
      $output->writeln("<info>{$composerFile} updated: Patch entry added for <comment>{$packageName}</comment></info>");
    }
    else {
      $output->writeln("<comment>Patch already listed in {$composerFile} for {$packageName}</comment>");
    }
  }

  /**
   * Get relative path from base to target
   */
  protected function getRelativePath($base, $target) {
    // Normalize paths
    $base = realpath($base);
    $target = realpath($target);
    if ($base === FALSE || $target === FALSE) {
      return $target;
    }
    $base = explode(DIRECTORY_SEPARATOR, $base);
    $target = explode(DIRECTORY_SEPARATOR, $target);
    // Find divergence point
    while (count($base) && count($target) && ($base[0] == $target[0])) {
      array_shift($base);
      array_shift($target);
    }
    return str_repeat('../', count($base)) . implode('/', $target);
  }

  protected function getCacheDir(Composer $composer) {
    if ($this->cacheDir === NULL) {
      // If --no-cache is passed to Composer, we need a different location to
      // download patches to. When --no-cache is passed, $composer_cache is
      // set to /dev/null.
      $composer_cache = $composer->getConfig()->get('cache-dir');
      if (!is_dir($composer_cache)) {
        $composer_cache = sys_get_temp_dir();
      }

      // If the cache directory doesn't exist, create it.
      $this->cacheDir = $composer_cache . '/' . GenerateVendorPatches::PLUGIN_NAME;
      if (!is_dir($this->cacheDir)) {
        mkdir($this->cacheDir);
      }
    }
    return $this->cacheDir;
  }

  protected function getComposerJsonFile() {
    return Factory::getComposerFile();
  }

}
