
Host your plugin in a private repository on BitBucket and have Wordpress pick up every new release and update like normal.

## Installing

Inside your plugin folder:

    composer require maneuver/bitbucket-wp-updater

## Usage

In your main plugin file:

    require('vendor/autoload.php');

    $repo = '';                 // name of your repository
    $bitbucket_username = '';   // your BitBucket username
    $bitbucket_app_pass = '';   // the generated app password with read access

    new \Maneuver\BitbucketWpUpdater\PluginUpdater(__FILE__, $repo, $bitbucket_username, $bitbucket_app_pass);

*NOTE: [Read more about BitBucket app passwords](https://confluence.atlassian.com/bitbucket/app-passwords-828781300.html)*

Update the version number inside your main plugin file:

    /*
    Plugin Name: Awesome Plugin
    Description: This is what your awesome plugin does.
    Version: 1.0.0
    Author: Your name
    */
   
And tag the commit:

    git tag v1.0.0

*NOTE: Use basic SemVer notation (a leading 'v' is allowed).*


## Caveats

Only works on activated plugins. For now.