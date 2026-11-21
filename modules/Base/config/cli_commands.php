<?php

/**
 * Base's CLI commands, the name typed after cmd= => the action it runs.
 *
 * Base\Module::registerCliCommands() registers each entry with
 * registerCliCommand(). The action must be in config/actions.php.
 */
return [
    'update'                      => 'base.updatesApplyCli',
    // These two run without OWA booted (Controller\Cli::needsBoot()).
    'version-info'                => 'base.versionInfoCli',
    'release-manifest'            => 'base.releaseManifestCli',
    'strings-extract'             => 'base.stringsExtractCli',
    'v1-drop'                     => 'base.v1DropCli',
    'backup'                      => 'base.backupCli',
    'fetch-notifications'         => 'base.notificationsFetchCli',
    'update-ua-regexes'           => 'base.updateUaRegexesCli',
    'drain-tracker-ingest'        => 'base.drainTrackerIngestCli',
    'tracker-ingest-replay'       => 'base.trackerIngestReplayCli',
    'tracker-ingest-provision'    => 'base.trackerIngestProvisionCli',
    'install'                     => 'base.installCli',
    'activate'                    => 'base.moduleActivateCli',
    'deactivate'                  => 'base.moduleDeactivateCli',
    'install-module'              => 'base.moduleInstallCli',
    'add-site'                    => 'base.sitesAddCli',
    'prune-event-queue-archives'  => 'base.pruneEventQueueArchivesCli',
    'partition-status'            => 'base.partitionStatusCli',
    'partition-drop'              => 'base.partitionDropCli',
    'partition-reorganize'        => 'base.partitionReorganizeCli',
    'partition-rotate'            => 'base.partitionRotateCli',
    'change-password'             => 'base.changeUserPasswordCli',
    'app-password-create'         => 'base.appPasswordCreateCli',
    'app-password-list'           => 'base.appPasswordListCli',
    'app-password-revoke'         => 'base.appPasswordRevokeCli',
    'reset-secrets'               => 'base.resetSecretsCli',
    'schedule-run'                => 'base.scheduleRunCli',
    'schedule-status'             => 'base.scheduleStatusCli',
    'instance-info'               => 'base.instanceInfoCli',
    'server-check'                => 'base.serverCheckCli',
    'cube-rebuild'                => 'base.cubeRebuildCli',
    'jobs'                        => 'base.jobsCli',
    'jobs-retry'                  => 'base.jobsRetryCli',
    'jobs-forget'                 => 'base.jobsForgetCli',
    'jobs-prune'                  => 'base.jobsPruneCli',
    'publish-trackers'            => 'base.trackerPublishCli',
    'custom-dimension-list'       => 'base.customDimensionListCli',
    'custom-dimension-apply'      => 'base.customDimensionApplyCli',
    'custom-dimension-register'   => 'base.customDimensionRegisterCli',
    'custom-dimension-deregister' => 'base.customDimensionDeregisterCli',
];
