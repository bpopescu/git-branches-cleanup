<?php

namespace bpopescu\GitBranchesCleanup;

use JsonException;
use RuntimeException;

class GitBranches
{
    const REST_API = '/rest/api/2/';
    const SPLIT = '************************************************';

    private string $gitPath;
    private array $branchesToDelete = [];
    private array $tasks = [];
    private string $token;
    private array $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    private array $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false
    ];
    private array $users;
    private string $host;
    private int $weeks;
    private array $statuses;
    private string $project;
    private ?string $gitFolder;

    public function run(array $args)
    {
        $this->setUp($args);
        $this->findTasks();
        $this->processBranches();


        if (!empty($this->branchesToDelete)) {
            print self::SPLIT . PHP_EOL;
            print '***** Run command to delete local branches *****' . PHP_EOL;
            print 'git branch -d ' . implode(' ', $this->branchesToDelete) . PHP_EOL;
            print self::SPLIT . PHP_EOL;
            print '***** Run command to delete remote branches *****' . PHP_EOL;
            print 'git push origin --delete ' . implode(' ', $this->branchesToDelete) . PHP_EOL;
            print self::SPLIT . PHP_EOL;
        }
    }

    private function setUp(array $args)
    {
        $configIni = parse_ini_file($this->getIniFile($args));
        $this->token = $configIni['token'];
        $this->users = (isset($configIni['users']) && is_array($configIni['users'])) ? $configIni['users'] : ['currentUser()'];
        $this->statuses = (isset($configIni['statuses']) && is_array($configIni['statuses'])) ? $configIni['statuses'] : ['Resolved'];
        $this->weeks = isset($configIni['weeks']) ? (int)$configIni['weeks'] : 12;
        $this->host = rtrim($configIni['host'] ?? '', '/');
        $this->project = $configIni['project'] ?? '';
        $this->gitFolder = $configIni['git_folder'] ?? null;
    }


    public function processBranches()
    {
        $branches = array_map('trim', $this->runGitCommand());
        print "Found " . count($branches) . " branches" . PHP_EOL;
        foreach ($branches as $branch) {
            $taskKey = $this->getTaskKey($branch) ?? 'UNKNOWN';
            $this->branchesToDelete[] = str_replace('origin/', '', $branch);

            print "TaskID: $taskKey - $branch " . PHP_EOL;
        }
    }

    public function findTasks()
    {
        $jql = [
            'project = ' . $this->project,
            'status in (' . implode(', ', $this->statuses) . ')',
            'updated >= -' . $this->weeks . 'w',
            'assignee IN (' . implode(', ', $this->users) . ')'
        ];
        $jqlString = implode(' AND ', $jql);
        $url = $this->host . self::REST_API . 'search/?jql=' . urlencode($jqlString) . '&fields=summary';
        print 'Searching for tickets with the following jql: ' . $jqlString . PHP_EOL;
        print 'Calling URL: ' . $url . PHP_EOL;
        try {
            $response = json_decode(
                $this->getJiraResponse($url),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (RuntimeException|JsonException $exception) {
            print self::SPLIT . PHP_EOL;
            print $exception->getMessage() . PHP_EOL;
            print self::SPLIT . PHP_EOL;
            die;
        }
        print 'Found ' . $response->total . ' tickets' . PHP_EOL;
        foreach ($response->issues as $issue) {
            $this->tasks[] = $issue->key;
        }
    }

    private function getTaskKey(string $branch): ?string
    {
        $matches = [];
        preg_match('/([A-Z]+-\d+)/i', $branch, $matches);
        if (!empty($matches[1])) {
            return $matches[1];
        }
        return null;
    }

    public function getGitPath()
    {
        if (!isset($this->gitPath)) {
            $command = 'which git';
            $output = null;
            exec($command, $output);
            if (!empty($output[0])) {
                $this->gitPath = $output[0];
                if (!empty($this->gitFolder)) {
                    $this->gitPath .= ' -C ' . $this->gitFolder;
                }
            }
        }
        return $this->gitPath;
    }

    /**
     * @throws RuntimeException
     */
    private function getJiraResponse(string $url): string
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
        ]);
        curl_setopt_array($curl, $this->curlOpts);
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => array_merge(
                $this->headers,
                ['Authorization: Bearer ' . $this->token]
            )
        ]);
        $response = curl_exec($curl);
        $error = curl_error($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($response === false) {
            throw new GitBranchesException($error);
        }
        if ($status != 200) {
            $response = json_decode($response);
            $message = '';
            if (false !== $response) {
                if (isset($response->errorMessages)) {
                    $message = implode('; ', $response->errorMessages);
                }
                if (isset($response->message)) {
                    $message = $response->message;
                }
            }
            throw new GitBranchesException($message . ' received when trying to access ' . $url, $status);
        }
        return $response;
    }

    private function runGitCommand(): array
    {
        if (empty($this->tasks)) {
            return [];
        }
        $command = $this->getGitPath() . ' branch -r | grep -iE "' . implode('|', $this->tasks) . '"';
        $output = [];
        exec($command, $output);
        return $output;
    }

    private function getIniFile(array $args): string
    {
        $defaultIni = __DIR__ . '/GitBranches.ini';
        if (file_exists($defaultIni)) {
            $file = $defaultIni;
        }
        if (empty($file) && !isset($args[1])) {
            print 'Set the details!' . PHP_EOL;
            print 'php ' . $args[0] . ' {jiraConfigINI}' . PHP_EOL;
            die;
        } else {
            if (empty($file)) {
                $file = (string)$args[1];
                if (!file_exists($file)) {
                    print 'No config file found.' . PHP_EOL;
                    die;
                }
            }
        }
        return $file;
    }
}
