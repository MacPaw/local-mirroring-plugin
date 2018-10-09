## Composer Local Mirroring Plugin
This plugin added functionality for composer to work with PHP monorepo:
- Added "exclude" config to path repository options, to ignore some files to be copied
- Added "remove-paths" config to extra for cleaning up before mirroring local dependencies

### composer.json example
This config will allow to update all libs of yours `company-ns` vendor which located in `../../libs/*`. Folders `vendor` and `test` will not be copied.

```
{
    "repositories": [
        {
            "type": "path",
            "url": "../../libs/*",
            "options": {
                "symlink": false,
                "exclude": ["vendor", "tests"]
            }
        }
    ],
    "extra": {
        "remove-paths": [
            "company-ns"
        ]
    }
}
```
