<?php

namespace AcquiaCli\Commands;

use AcquiaCloudApi\CloudApi\Client;
use AcquiaCloudApi\Response\CloudApiResponse;
use Symfony\Component\Console\Helper\Table;

/**
 * Class InfoCommand
 * @package AcquiaCli\Commands
 */
class InfoCommand extends AcquiaCommand
{

    /**
     * Gets all tasks associated with a site.
     *
     * @param string $site
     *
     * @command task:list
     * @alias t:l
     */
    public function acquiaTasks($site)
    {

        $output = $this->output();
        $table = new Table($output);
        $table->setHeaders(array('ID', 'User', 'State', 'Description'));

        $tasks = $this->cloudapi->tasks($site);
        /** @var Task $task */
        foreach ($tasks as $task) {
            $table
                ->addRows(array(
                    array($task->id(), $task->sender(), $task->state(), $task->description()),
                ));
        }

        $table->render();
    }

    /**
     * Gets detailed information about a specific task
     *
     * @param string $site
     * @param string $taskId
     *
     * @command task:info
     * @alias t:i
     */
    public function acquiaTask($site, $taskId)
    {

        $tz = $this->extraConfig['timezone'];
        $format = $this->extraConfig['format'];

        $task = $this->cloudapi->task($site, $taskId);
        $startedDate = new \DateTime();
        $startedDate->setTimestamp($task->startTime());
        $startedDate->setTimezone(new \DateTimeZone($tz));
        $completedDate = new \DateTime();
        $completedDate->setTimestamp($task->startTime());
        $completedDate->setTimezone(new \DateTimeZone($tz));
        $task->created()->setTimezone(new \DateTimeZone($tz));

        $this->say('ID: ' . $task->id());
        $this->say('Sender: ' . $task->sender());
        $this->say('Description: ' . $task->description());
        $this->say('State: ' . $task->state());
        $this->say('Created: ' . $task->created()->format($format));
        $this->say('Started: ' . $startedDate->format($format));
        $this->say('Completed: ' . $completedDate->format($format));
        $this->say('Logs: ' . $task->logs());
    }

    /**
     * Shows all sites a user has access to.
     *
     * @command application:list
     * @alias app:list
     * @alias a:l
     */
    public function acquiaApplications()
    {
        $sites = $this->cloudapi->applications();

        $output = $this->output();
        $table = new Table($output);
        $table->setHeaders(array('Name', 'UUID'));
        foreach ($sites as $site) {
            $table
                ->addRows(array(
                    array($site->name, $site->uuid),
                ));
        }
        $table->render();
    }

    /**
     * Shows detailed information about a site.
     *
     * @param string $uuid
     *
     * @command application:info
     * @alias app:info
     * @alias a:i
     */
    public function acquiaApplicationInfo($uuid)
    {
        $environments = $this->cloudapi->environments($uuid);

        $output = $this->output();
        $table = new Table($output);
        $table->setHeaders(array('Environment', 'ID', 'Branch/Tag', 'Domain(s)', 'Database(s)'));

        foreach ($environments as $environment) {
            $vcs = $environment->vcs->path;

            $databases = $this->cloudapi->environmentDatabases($environment->id);

            $dbs = [];
            foreach ($databases as $database) {
                $dbs[] = $database->name;
            }
            $dbString = implode(', ', $dbs);

            $environmentName = $environment->label . ' (' . $environment->name . ')' ;
            if ($environment->flags->livedev) {
                $environmentName = '💻  ' . $environmentName;
            }

            if ($environment->flags->production_mode) {
                $environmentName = '🔒  ' . $environmentName;
            }

            $table
                ->addRows(array(
                    array($environmentName, $environment->id, $vcs, implode("\n", $environment->domains), $dbString),
                ));
        }
        $table->render();
        $this->say('💻  indicates environment in livedev mode.');
        $this->say('🔒  indicates environment in production mode.');

    }

    /**
     * Shows detailed information about servers in an environment.
     *
     * @param string      $uuid
     * @param string|null $environment
     *
     * @command environment:info
     * @alias env:info
     * @alias e:i
     */
    public function acquiaEnvironmentInfo($uuid, $environment = null)
    {

        if (null !== $environment) {
            $this->cloudapi->addFilter('name', '=', $environment);
        }

        $environments = $this->cloudapi->environments($uuid);

        $this->cloudapi->clearQuery();

        foreach ($environments as $e) {
            $this->renderEnvironmentInfo($e);
        }

        $this->say("Web servers not marked 'Active' are out of rotation.");
        $this->say("Load balancer servers not marked 'Active' are hot spares");
        $this->say("Database servers not marked 'Primary' are the passive master");
    }

    /**
     * @param $site
     * @param $environment
     */
    protected function renderEnvironmentInfo($environment)
    {

        $environmentName = $environment->label;
        $environmentId = $environment->id;

        $this->yell("${environmentName} environment");
        $this->say("Environment ID: ${environmentId}");
        if ($environment->flags->livedev) {
            $this->say('💻  Livedev mode enabled.');
        }
        if ($environment->flags->production_mode) {
            $this->say('🔒  Production mode enabled.');
        }

        $output = $this->output();
        $table = new Table($output);
        // needs AZ?
        $table->setHeaders(array('Role(s)', 'Name', 'FQDN', 'AMI', 'Region', 'IP', 'Memcache', 'Active', 'Primary', 'EIP'));

        $servers = $this->cloudapi->servers($environment->id);

        foreach ($servers as $server) {

            $memcache = $server->flags->memcache ? '✅' : '';
            $active = $server->flags->active_web || $server->flags->active_bal ? '✅' : '';
            $primaryDb = $server->flags->primary_db ? '✅' : '';
            $eip = $server->flags->elastic_ip ? '✅' : '';

            $table
                ->addRows(array(
                    array(implode(', ', $server->roles), $server->name, $server->hostname, $server->ami_type, $server->region, $server->ip, $memcache, $active, $primaryDb, $eip),
                ));
        }

        $table->render();

    }

    /**
     * Shows SSH connection strings for specified environments.
     *
     * @param string      $uuid
     * @param string|null $environment
     *
     * @command ssh:info
     */
    public function acquiaSshInfo($uuid, $environment = null)
    {
        if (null !== $environment) {
            $this->cloudapi->addFilter('name', '=', $environment);
        }

        $environments = $this->cloudapi->environments($uuid);

        $this->cloudapi->clearQuery();

        foreach ($environments as $e) {
            $this->renderSshInfo($e);
        }
    }

    private function renderSshInfo($environment)
    {
        $environmentName = $environment->name;
        $ssh = $environment->ssh_url;
        $this->say("${environmentName}: ssh ${ssh}");
    }
}
