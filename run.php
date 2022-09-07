<?php

require_once './vendor/autoload.php';

use bpopescu\GitBranchesCleanup\GitBranches;

(new GitBranches())->run($argv);
