<?php

namespace Maneuver\BitbucketWpUpdater;

class PluginUpdater {
  private $slug;
  private $pluginData;
  private $repo;
  private $pluginFile;
  private $bitbucketAPIResult;
  private $bitbucketUsername;
  private $bitbucketPassword;

  function __construct($pluginFile, $repo, $bbUsername, $bbPassword) {
    add_filter( "pre_set_site_transient_update_plugins", array( $this, "setTransient" ) );
    add_filter( "plugins_api", array( $this, "setPluginInfo" ), 10, 3 );
    add_filter( "upgrader_post_install", array( $this, "postInstall" ), 10, 3 );
    add_filter('http_request_args', array($this, 'modifyRequestArgs'), 10, 2);

    $this->pluginFile = $pluginFile;
    $this->repo = $repo;
    $this->bitbucketUsername = $bbUsername;
    $this->bitbucketPassword = $bbPassword;
  }

  // Get information regarding our plugin from WordPress
  private function initPluginData() {
    $this->slug = plugin_basename( $this->pluginFile );
    $this->pluginData = get_plugin_data( $this->pluginFile );
  }

  private function makeRequest($url) {
    $process = curl_init($url);
    curl_setopt($process, CURLOPT_USERPWD, sprintf('%s:%s', $this->bitbucketUsername, $this->bitbucketPassword));
    curl_setopt($process, CURLOPT_RETURNTRANSFER, TRUE);
    $response = curl_exec($process);
    curl_close($process);

    return $response;
  }

  // Get information regarding our plugin from GitHub
  private function getRepoReleaseInfo() {

    // Only do this once
    if ( ! empty( $this->bitbucketAPIResult ) ) {
      return;
    }

    $url = sprintf('https://api.bitbucket.org/2.0/repositories/%s/refs/tags?sort=-target.date', $this->repo);

    $response = $this->makeRequest($url);

    if ($response) {
      $data = json_decode($response);
      if (isset($data, $data->values) && is_array($data->values)) {
        $tag = reset($data->values);

        if (isset($tag->name)) {
          $this->bitbucketAPIResult = $tag;
        }
      }
    }
  }

  // Push in plugin version information to get the update notification
  public function setTransient( $transient ) {
    // var_dump($transient);exit;
    
    // If we have checked the plugin data before, don't re-check
    if ( empty( $transient->checked ) ) {
      return $transient;
    }

    // Get plugin & GitHub release information
    $this->initPluginData();
    $this->getRepoReleaseInfo();

    if (empty($this->bitbucketAPIResult)) {
      // Nothing found.
      return $transient;
    }

    $bb_version = str_replace('v', '', $this->bitbucketAPIResult->name);

    // Check the versions if we need to do an update
    $doUpdate = version_compare($bb_version, $transient->checked[$this->slug]);

    // Update the transient to include our updated plugin data
    if ( $doUpdate == 1 ) {
      $package = sprintf('https://bitbucket.org/%s/get/%s.zip', 
        $this->repo, 
        $this->bitbucketAPIResult->name
      );
   
      $obj = new \stdClass();
      $obj->slug = $this->slug;
      $obj->new_version = str_replace('v', '', $this->bitbucketAPIResult->name);
      $obj->url = $this->pluginData["PluginURI"];
      $obj->package = $package;
      $obj->compatibility = 
      $transient->response[$this->slug] = $obj;
    }

    return $transient;
  }

  public function getReadmeFile() {
    $sha = 'HEAD';

    $url = sprintf('https://bitbucket.org/%s/raw/%s/readme.txt', 
      $this->repo, 
      $sha
    );

    $url = sprintf('https://api.bitbucket.org/2.0/repositories/%s/src/HEAD/readme.txt', $this->repo);

    $response = $this->makeRequest($url);

    $decode = json_decode($response);

    // No file found or other error.
    if ($decode) {
      return false;
    }

    return $response;
  }

  public function modifyRequestArgs($args, $url) {
    if (preg_match('/bitbucket.org(.+)' . str_replace('/', '\/', $this->repo) . '/', $url)) {
      if (empty($args['headers'])) {
        $args['headers'] = array();
      }
      $args['headers']['Authorization'] = 'Basic ' . base64_encode($this->bitbucketUsername . ':' . $this->bitbucketPassword);
    }
    return $args;
  }

  // Push in plugin version information to display in the details lightbox
  public function setPluginInfo( $res, $action, $args ) {
    $this->initPluginData();

    if ($action == 'plugin_information' && $args->slug == $this->slug) {
      $res = new \stdClass();
      $res->name = $this->pluginData['Name'];
      $res->slug = $this->slug;

      $changelog = 'No readme file present in repo.';

      $readme = $this->getReadmeFile();
      if ($readme) {
        $Parsedown = new \Parsedown();
        $changelog = $Parsedown->text($readme);
      }

      $res->sections = [
        'changelog' => $changelog,
      ];
    }

    return $res;
  }

  // Perform additional actions to successfully install our plugin
  public function postInstall( $true, $hook_extra, $result ) {
    // Get plugin information
    $this->initPluginData();

    // Remember if our plugin was previously activated
    $wasActivated = is_plugin_active( $this->slug );

    // Since we are hosted in GitHub, our plugin folder would have a dirname of
    // reponame-tagname change it to our original one:
    global $wp_filesystem;
    $pluginFolder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname( $this->slug );
    $wp_filesystem->move( $result['destination'], $pluginFolder );
    $result['destination'] = $pluginFolder;

    // Re-activate plugin if needed
    if ( $wasActivated ) {
      $activate = activate_plugin( $this->slug );
    }

    return $result;
  }
}