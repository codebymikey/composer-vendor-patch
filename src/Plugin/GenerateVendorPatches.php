<?php

namespace Codebymikey\ComposerVendorPatch\Plugin;

use Codebymikey\ComposerVendorPatch\PluginCommandProvider;
use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

class GenerateVendorPatches implements PluginInterface, Capable, EventSubscriberInterface {

  const PLUGIN_NAME = 'composer-vendor-patch';

  /**
   * {@inheritdoc}
   */
  public function getCapabilities(): array {
    return [
      CommandProvider::class => PluginCommandProvider::class,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function activate(Composer $composer, IOInterface $io) {
  }

  /**
   * {@inheritdoc}
   */
  public function deactivate(Composer $composer, IOInterface $io) {
  }

  /**
   * {@inheritdoc}
   */
  public function uninstall(Composer $composer, IOInterface $io) {
  }
}
