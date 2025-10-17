# codebymikey/composer-vendor-patch

_Composer vendor patch_ is a [composer][composer] plugin that allows users to easily generate patches based
on local changes to a vendor package.

The idea is to use the generated patches in tandem with the [composer-patches][composer-patches] plugin.

## How to Use

Simply run `composer generate-vendor-patches <package-name...>` and the patches for said package should be generated.

### Configuration

The default plugin configurations are as follows:
```json5
{
  // composer.json
  "extra": {
    "composer-vendor-patch": {
      "patch-dir": "patches",
      "patch-format": "{vendor}__{name}.diff"
    }
  }
}
```

Where `patch-dir` and `patch-format` supports the following placeholders:
* `{vendor}` - The package vendor namespace.
* `{name}` - The package name.
* `{version}` - The package version.
* `{type}` - The type of the package.
* `{sourceReference}` - The source reference (may be empty).
* `{distReference}` - The dist reference (may be empty).
* `{distSha1Checksum}` - The dist sha1 reference (may be empty).

However, these configurations may be overridden for specific packages if necessary:
```json5
{
  // composer.json
  "extra": {
    "composer-vendor-patch": {
      "patch-dir": {
        "vendor/package-1": "patches",
        "vendor/package-2": "second-patches-dir/${vendor}",
      },
      "patch-format": {
        "vendor/package-1": "{vendor}__{name}.diff"
      }
    }
  }
}
```

[composer]: https://getcomposer.org/
[composer-patches]: https://github.com/cweagans/composer-patches
