<?php

namespace Codebymikey\ComposerVendorPatch;

use Composer\Plugin\Capability\CommandProvider;

/**
 * The composer plugin command provider.
 */
class PluginCommandProvider implements CommandProvider {

    /**
     * {@inheritdoc}
     */
    public function getCommands(): array {
        return [
            new GenerateVendorPatchesCommand(),
        ];
    }

}
