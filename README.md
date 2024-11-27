# wp-versioncheck

Fetches and scans the latest (including beta/rc rleases) WordPress source code for `@since` and `@deprecated` annotations and caches the information in `since_data.json`.

This information is then used to find out the minimum needed WordPress version based on a plugin's or theme's source code. Optionally, 

## Usage

`php wpvc.php [Options] "Path/to/wp/plugin/or/theme/source"

If no path is supplied, it will use the current working dir.

### Options

`--suc`, `--skip-update-check`: Skip update check completely
`--su`, `--skip-update`: Skip updating version data if a new WordPress version is found
`--save`, `--save-usage-data`: Save `wpvc_usage_data.json` to the source directory
`--update`, `--update-files`: Update the main file and readme.txt with the found minimum version
`--ufo`, `--update-files-only`: Only update main file and readme.txt with the versions supplied via `--php` and/or `--wp` arguments, without any processing
