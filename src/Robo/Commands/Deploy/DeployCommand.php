<?php

namespace Acquia\Blt\Robo\Commands\Deploy;

use Acquia\Blt\Robo\BltTasks;
use Acquia\Blt\Robo\Exceptions\BltException;
use Robo\Contract\VerbosityThresholdInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Finder\Finder;

/**
 * Defines commands in the "deploy:*" namespace.
 */
class DeployCommand extends BltTasks {

  protected $createTag = FALSE;
  protected $tagName = NULL;
  protected $branchName;
  protected $commitMessage;
  protected $excludeFileTemp;
  protected $deployDir;
  protected $tagSource;
  protected $ignorePlatformReqs = FALSE;

  /**
   * This hook will fire for all commands in this command file.
   *
   * @hook init
   */
  public function initialize() {
    $this->excludeFileTemp = $this->getConfigValue('deploy.exclude_file') . '.tmp';
    $this->deployDir = $this->getConfigValue('deploy.dir');
    $this->tagSource = $this->getConfigValue('deploy.tag_source', TRUE);
  }

  /**
   * Builds separate artifact and pushes to git.remotes defined blt.yml.
   *
   * @param array $options
   *   Options that can be passed via the CLI.
   *
   * @command artifact:deploy
   *
   * @aliases ad deploy
   *
   * @validateGitConfig
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   */
  public function deploy(array $options = [
    'branch' => InputOption::VALUE_REQUIRED,
    'tag' => InputOption::VALUE_REQUIRED,
    'commit-msg' => InputOption::VALUE_REQUIRED,
    'ignore-dirty' => FALSE,
    'dry-run' => FALSE,
    'ignore-platform-reqs' => FALSE,
  ]) {
    if ($options['dry-run']) {
      $this->logger->warning("This will be a dry run, the artifact will not be pushed.");
    }
    $this->checkDirty($options);

    if (isset($options['ignore-platform-reqs'])) {
      $this->ignorePlatformReqs = $options['ignore-platform-reqs'];
    }

    if (!$options['tag'] && !$options['branch']) {
      $this->createTag = $this->confirm("Would you like to create a tag?", $this->createTag);
    }
    $this->commitMessage = $this->getCommitMessage($options);

    if ($options['tag'] || $this->createTag) {
      // Warn if they're creating a tag and we won't tag the source for them.
      if (!$this->tagSource) {
        $this->say("Config option deploy.tag_source if FALSE. The source repo will not be tagged.");
      }
      $this->deployToTag($options);
    }
    else {
      $this->deployToBranch($options);
    }
  }

  /**
   * Checks to see if current git branch has uncommitted changes.
   *
   * @param array $options
   *   Set ignore-dirty to false to disable checks for dirty Git directory.
   *
   * @command deploy:check-dirty
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   *   Thrown if there are uncommitted changes.
   */
  public function checkDirty(array $options = ['ignore-dirty' => FALSE]) {
    $result = $this->taskExec('git status --porcelain')
      ->printMetadata(FALSE)
      ->printOutput(TRUE)
      ->interactive(FALSE)
      ->run();
    if (!$options['ignore-dirty'] && !$result->wasSuccessful()) {
      throw new BltException("Unable to determine if local git repository is dirty.");
    }

    $dirty = (bool) $result->getMessage();
    if ($dirty) {
      if ($options['ignore-dirty']) {
        $this->logger->warning("There are uncommitted changes on the source repository.");
      }
      else {
        throw new BltException("There are uncommitted changes, commit or stash these changes before deploying.");
      }
    }
  }

  /**
   * Gets the commit message to be used for committing deployment artifact.
   *
   * Defaults to the last commit message on the source branch.
   *
   * @return string
   *   The commit message.
   */
  protected function getCommitMessage($options) {
    if (!$options['commit-msg']) {
      chdir($this->getConfigValue('repo.root'));
      $log = explode(' ', shell_exec("git log --oneline -1"), 2);
      $git_last_commit_message = trim($log[1]);

      return $this->askDefault('Enter a valid commit message', $git_last_commit_message);
    }
    else {
      $this->say("Commit message is set to <comment>{$options['commit-msg']}</comment>.");
      return $options['commit-msg'];
    }
  }

  /**
   * Gets the branch name for the deployment artifact.
   *
   * Defaults to [current-branch]-build.
   *
   * @return string
   *   The branch name.
   */
  protected function getBranchName($options) {
    if ($options['branch']) {
      $this->say("Branch is set to <comment>{$options['branch']}</comment>.");
      return $options['branch'];
    }
    else {
      return $this->askDefault('Enter the branch name for the deployment artifact', $this->getDefaultBranchName());
    }
  }

  /**
   * Gets the name of the tag to cut.
   *
   * @param array $options
   *   Options.
   *
   * @return string
   *   Name.
   *
   * @throws \Exception
   */
  protected function getTagName(array $options) {
    if ($options['tag']) {
      $tag_name = $options['tag'];
    }
    else {
      $tag_name = $this->ask('Enter the tag name for the deployment artifact, e.g., 1.0.0-build');
    }

    if (empty($tag_name)) {
      // @todo Validate tag name is valid, e.g., no spaces or special characters.
      throw new BltException("You must enter a valid tag name.");
    }
    else {
      $this->say("Tag is set to <comment>$tag_name</comment>.");
    }

    return $tag_name;
  }

  /**
   * Gets the default branch name for the deployment artifact.
   */
  protected function getDefaultBranchName() {
    chdir($this->getConfigValue('repo.root'));
    $git_current_branch = trim(shell_exec("git rev-parse --abbrev-ref HEAD"));
    $default_branch = $git_current_branch . '-build';

    return $default_branch;
  }

  /**
   * Creates artifact, cuts new tag, and pushes.
   */
  protected function deployToTag($options) {
    $this->tagName = $this->getTagName($options);

    // If we are building a tag, then we assume that we will NOT be pushing the
    // build branch from which the tag is created. However, we must still have a
    // local branch from which to cut the tag, so we create a temporary one.
    $this->branchName = $this->getDefaultBranchName() . '-temp';
    $this->prepareDir();
    $this->addGitRemotes();
    $this->checkoutLocalDeployBranch();
    $this->build();
    $this->commit();
    $this->cutTag('build');

    // Check the deploy.tag_source config value and also tag the source repo if
    // it is set to TRUE (the default).
    if ($this->tagSource) {
      $this->cutTag('source');
    }

    $this->push($this->tagName, $options);
  }

  /**
   * Creates artifact on branch and pushes.
   */
  protected function deployToBranch($options) {
    $this->branchName = $this->getBranchName($options);
    $this->prepareDir();
    $this->addGitRemotes();
    $this->checkoutLocalDeployBranch();
    $this->mergeUpstreamChanges();
    $this->build();
    $this->commit();
    $this->push($this->branchName, $options);
  }

  /**
   * Deletes the existing deploy directory and initializes git repo.
   */
  protected function prepareDir() {
    $this->say("Preparing artifact directory...");
    $deploy_dir = $this->deployDir;
    $this->taskDeleteDir($deploy_dir)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->run();
    $this->taskFilesystemStack()
      ->mkdir($this->deployDir)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->stopOnFail()
      ->run();
    $this->taskExecStack()
      ->dir($deploy_dir)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->stopOnFail()
      ->exec("git init")
      ->exec("git config --local core.excludesfile false")
      ->exec("git config --local core.fileMode true")
      ->run();
    $this->say("Global .gitignore file is being disabled for this repository to prevent unexpected behavior.");
  }

  /**
   * Adds remotes from git.remotes to /deploy repository.
   */
  protected function addGitRemotes() {
    $git_remotes = $this->getConfigValue('git.remotes');
    if (empty($git_remotes)) {
      throw new BltException("git.remotes is empty. Please define at least one value for git.remotes in blt/blt.yml.");
    }
    foreach ($git_remotes as $remote_url) {
      $this->addGitRemote($remote_url);
    }
  }

  /**
   * Adds a single remote to the /deploy repository.
   *
   * @param string $remote_url
   *   Remote URL.
   */
  protected function addGitRemote($remote_url) {
    // Generate an md5 sum of the remote URL to use as remote name.
    $remote_name = md5($remote_url);
    $this->taskExecStack()
      ->stopOnFail()
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->dir($this->deployDir)
      ->exec("git remote add $remote_name $remote_url")
      ->run();
  }

  /**
   * Checks out a new, local branch for artifact.
   */
  protected function checkoutLocalDeployBranch() {
    $this->taskExecStack()
      ->dir($this->deployDir)
      ->stopOnFail()
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->exec("git checkout -b {$this->branchName}")
      ->run();
  }

  /**
   * Merges upstream changes into deploy branch.
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   */
  protected function mergeUpstreamChanges() {
    $git_remotes = $this->getConfigValue('git.remotes');
    $remote_url = reset($git_remotes);
    $remote_name = md5($remote_url);

    $this->say("Merging upstream changes into local artifact...");

    // Check if remote branch exists before fetching.
    $result = $this->taskExecStack()
      ->dir($this->deployDir)
      ->stopOnFail(FALSE)
      ->exec("git ls-remote --exit-code --heads $remote_url {$this->branchName}")
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->run();
    switch ($result->getExitCode()) {
      case 0:
        // The remote branch exists, continue and merge it.
        break;

      case 2:
        // The remote branch doesn't exist, bail out.
        return;

      default:
        // Some other error code.
        throw new BltException("Unexpected error while searching for remote branch: " . $result->getMessage());
    }

    // Now we know the remote branch exists, let's fetch and merge it.
    $this->taskExecStack()
      ->dir($this->deployDir)
      ->stopOnFail()
      ->exec("git fetch $remote_name {$this->branchName} --depth=1")
      ->exec("git merge $remote_name/{$this->branchName}")
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->run();
  }

  /**
   * Builds deployment artifact.
   *
   * @command artifact:build
   * @aliases ab deploy:build
   */
  public function build() {
    $this->say("Generating build artifact...");
    $this->say("For more detailed output, use the -v flag.");

    $commands = [
      // Execute `blt source:build:frontend` to ensure that frontend artifact
      // are generated in source repo.
      'source:build:frontend',
      // Execute `drupal:hash-salt:init` to ensure that salt.txt exists.
      // There's a slim chance this has never been generated.
      'drupal:hash-salt:init',
    ];
    if (!empty($this->tagName)) {
      $commands['drupal:deployment-identifier:init'] = ['--id' => $this->tagName];
    }
    else {
      $commands[] = 'drupal:deployment-identifier:init';
    }
    $this->invokeCommands($commands);

    $this->buildCopy();
    $this->composerInstall();
    $this->sanitize();
    $this->deploySamlConfig();
    $this->invokeHook("post-deploy-build");
    $this->say("<info>The deployment artifact was generated at {$this->deployDir}.</info>");
  }

  /**
   * Copies files from source repo into artifact.
   */
  protected function buildCopy() {
    $exclude_list_file = $this->getExcludeListFile();
    $source = $this->getConfigValue('repo.root');
    $dest = $this->deployDir;

    $this->setMultisiteFilePermissions(0777);
    $this->say("Rsyncing files from source repo into the build artifact...");
    $this->taskExecStack()->exec("rsync -a --no-g --delete --delete-excluded --exclude-from='$exclude_list_file' '$source/' '$dest/' --filter 'protect /.git/'")
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->stopOnFail()
      ->dir($this->getConfigValue('repo.root'))
      ->run();
    $this->setMultisiteFilePermissions(0755);

    // Remove temporary file that may have been created by
    // $this->getExcludeListFile().
    $this->taskFilesystemStack()
      ->remove($this->excludeFileTemp)
      ->copy(
        $this->getConfigValue('deploy.gitignore_file'),
        $this->deployDir . '/.gitignore', TRUE
      )
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->run();

  }

  /**
   * Installs composer dependencies for artifact.
   *
   * @return bool
   *   Bool.
   *
   * @throws \Robo\Exception\TaskException
   */
  protected function composerInstall() {
    if (!$this->getConfigValue('deploy.build-dependencies')) {
      $this->logger->warning("Dependencies will not be built because deploy.build-dependencies is not enabled");
      $this->logger->warning("You should define a custom deploy.exclude_file to ensure that dependencies are copied from the root repository.");

      return FALSE;
    }
    $this->say("Rebuilding composer dependencies for production...");
    $this->taskDeleteDir([$this->deployDir . '/vendor'])
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->run();
    $this->taskFilesystemStack()
      ->copy($this->getConfigValue('repo.root') . '/composer.json', $this->deployDir . '/composer.json', TRUE)
      ->copy($this->getConfigValue('repo.root') . '/composer.lock', $this->deployDir . '/composer.lock', TRUE)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->run();
    $command = 'composer install --no-dev --no-interaction --optimize-autoloader';
    if ($this->ignorePlatformReqs) {
      $command .= ' --ignore-platform-reqs';
    }
    $this->taskExecStack()->exec($command)
      ->stopOnFail()
      ->dir($this->deployDir)
      ->run();
  }

  /**
   * Removes sensitive files from the deploy dir.
   */
  protected function sanitize() {
    $this->say("Sanitizing artifact...");

    $this->logger->info("Find Drupal core text files...");
    $sanitizeFinder = Finder::create()
      ->files()
      ->name('*.txt')
      ->notName('LICENSE.txt')
      ->in("{$this->deployDir}/docroot/core");

    $this->logger->info('Find VCS directories...');
    $vcsFinder = Finder::create()
      ->ignoreDotFiles(FALSE)
      ->ignoreVCS(FALSE)
      ->directories()
      ->in(["{$this->deployDir}/docroot", "{$this->deployDir}/vendor"])
      ->name('.git');
    if ($vcsFinder->hasResults()) {
      $sanitizeFinder->append($vcsFinder);
    }

    $this->logger->info("Find Github directories...");
    $githubFinder = Finder::create()
      ->ignoreDotFiles(FALSE)
      ->directories()
      ->in(["{$this->deployDir}/docroot", "{$this->deployDir}/vendor"])
      ->name('.github');
    if ($githubFinder->hasResults()) {
      $sanitizeFinder->append($githubFinder);
    }

    $this->logger->info('Find INSTALL database text files...');
    $dbInstallFinder = Finder::create()
      ->files()
      ->in(["{$this->deployDir}/docroot"])
      ->name('/INSTALL\.[a-z]+\.(md|txt)$/');
    if ($dbInstallFinder->hasResults()) {
      $sanitizeFinder->append($dbInstallFinder);
    }

    $this->logger->info('Find other common text files...');
    $filenames = [
      'AUTHORS',
      'CHANGELOG',
      'CONDUCT',
      'CONTRIBUTING',
      'INSTALL',
      'MAINTAINERS',
      'PATCHES',
      'TESTING',
      'UPDATE',
    ];
    $textFileFinder = Finder::create()
      ->files()
      ->in(["{$this->deployDir}/docroot"])
      ->name('/(' . implode('|', $filenames) . ')\.(md|txt)$/');
    if ($textFileFinder->hasResults()) {
      $sanitizeFinder->append($textFileFinder);
    }

    $this->logger->info("Remove sanitized files from build...");
    $taskFilesystemStack = $this->taskFilesystemStack()
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE);
    foreach ($sanitizeFinder->getIterator() as $fileInfo) {
      $taskFilesystemStack->remove($fileInfo->getRealPath());
    }
    $taskFilesystemStack->run();
  }

  /**
   * Gets the file that lists the excludes for the artifact.
   */
  protected function getExcludeListFile() {
    $exclude_file = $this->getConfigValue('deploy.exclude_file');
    $exclude_additions = $this->getConfigValue('deploy.exclude_additions_file');
    if (file_exists($exclude_additions)) {
      $this->say("Combining exclusions from deploy.deploy-exclude-additions and deploy.deploy-exclude files...");
      $exclude_file = $this->mungeExcludeLists($exclude_file, $exclude_additions);
    }

    return $exclude_file;
  }

  /**
   * Combines deploy.exclude_file with deploy.exclude_additions_file.
   *
   * Creates a temporary file containing the combination.
   *
   * @return string
   *   The filepath to the temporary file containing the combined list.
   */
  protected function mungeExcludeLists($file1, $file2) {
    $file1_contents = file($file1);
    $file2_contents = file($file2);
    $merged = array_merge($file1_contents, $file2_contents);
    $merged_without_dups = array_unique($merged);
    file_put_contents($this->excludeFileTemp, $merged_without_dups);

    return $this->excludeFileTemp;
  }

  /**
   * Sets permissions for all multisite directories.
   */
  protected function setMultisiteFilePermissions($perms) {
    $taskFilesystemStack = $this->taskFilesystemStack();
    $multisites = $this->getConfigValue('multisites');
    foreach ($multisites as $multisite) {
      $multisite_dir = $this->getConfigValue('docroot') . '/sites/' . $multisite;
      $taskFilesystemStack->chmod($multisite_dir, $perms);
    }
    $taskFilesystemStack->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE);
    $taskFilesystemStack->run();
  }

  /**
   * Creates a commit on the artifact.
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   */
  protected function commit() {
    $this->say("Committing artifact to <comment>{$this->branchName}</comment>...");

    $result = $this->taskGit()
      ->dir($this->deployDir)
      ->exec("git rm -r --cached --ignore-unmatch --quiet .")
      ->add('-A')
      ->commit($this->commitMessage, '--quiet')
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->run();

    if (!$result->wasSuccessful()) {
      throw new BltException("Failed to commit deployment artifact!");
    }
  }

  /**
   * Pushes the artifact to git.remotes.
   *
   * @param string $identifier
   *   Identifier.
   * @param array $options
   *   Options.
   *
   * @return bool
   *   Bool.
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   */
  protected function push($identifier, array $options) {
    if ($options['dry-run']) {
      $this->logger->warning("Skipping push of deployment artifact. deploy.dryRun is set to true.");
      return FALSE;
    }
    else {
      $this->say("Pushing artifact to git.remotes...");
    }

    $task = $this->taskExecStack()
      ->dir($this->deployDir);
    foreach ($this->getConfigValue('git.remotes') as $remote) {
      $remote_name = md5($remote);
      $task->exec("git push $remote_name $identifier");
    }
    $result = $task->run();

    if (!$result->wasSuccessful()) {
      throw new BltException("Failed to push deployment artifact!");
    }
  }

  /**
   * Creates a tag on the build repository.
   *
   * @param string $repo
   *   The repo in which a tag should be cut.
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   */
  protected function cutTag($repo = 'build') {
    $taskGit = $this->taskGit()
      ->tag($this->tagName, $this->commitMessage)
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->stopOnFail();

    if ($repo == 'build') {
      $taskGit->dir($this->deployDir);
    }

    $result = $taskGit->run();
    if (!$result->wasSuccessful()) {
      throw new BltException("Failed to create Git tag!");
    }
    $this->say("The tag {$this->tagName} was created on the {$repo} repository.");
  }

  /**
   * Executes artifact:build:simplesamlphp-config command.
   */
  protected function deploySamlConfig() {
    if ($this->getConfigValue('simplesamlphp')) {
      $this->invokeCommand('artifact:build:simplesamlphp-config');
    }
  }

  /**
   * Update the database to reflect the state of the Drupal file system.
   *
   * @command artifact:update:drupal
   * @aliases aud deploy:update
   */
  public function update() {
    // Disable alias since we are targeting specific uri.
    $this->config->set('drush.alias', '');
    $this->updateSite($this->getConfigValue('site'));
  }

  /**
   * Update the database to reflect the state of the Drupal file system.
   *
   * @command artifact:update:drupal:all-sites
   * @aliases auda
   */
  public function updateAll() {
    // Disable alias since we are targeting specific uri.
    $this->config->set('drush.alias', '');

    foreach ($this->getConfigValue('multisites') as $multisite) {
      $this->updateSite($multisite);
    }
  }

  /**
   * Execute updates on a specific site.
   *
   * @param string $multisite
   *   Multisite.
   */
  protected function updateSite($multisite) {
    $this->say("Deploying updates to <comment>$multisite</comment>...");
    $this->switchSiteContext($multisite);

    $this->invokeCommand('drupal:update');

    $this->say("Finished deploying updates to $multisite.");
  }

  /**
   * Syncs database and files and runs updates.
   *
   * @command artifact:sync:all-sites
   * @aliases asas
   */
  public function syncRefresh() {
    // Disable alias since we are targeting specific uri.
    $this->config->set('drush.alias', '');

    // Sync files.
    $this->config->set('sync.files', TRUE);

    foreach ($this->getConfigValue('multisites') as $multisite) {
      $this->say("Syncing $multisite...");
      if (!$this->config->get('drush.uri')) {
        $this->config->set('drush.uri', $multisite);
      }

      $this->invokeCommand('drupal:sync:db');
      $this->invokeCommand('drupal:sync:files');
      $this->invokeCommand('drupal:update');

      $this->say("Finished syncing $multisite.");
    }
  }

  /**
   * Installs Drupal, imports config, and executes updates.
   *
   * @command artifact:install:drupal
   * @aliases aid deploy:drupal:install
   */
  public function installDrupal() {
    $this->invokeCommands([
      'internal:drupal:install',
      'artifact:update:drupal:all-sites',
    ]);
  }

}
