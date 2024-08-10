<?php
declare(strict_types=1);

namespace Autoframe\GitExecHook;

use Throwable;

/*
//Hook example
$oGhr = new AfrGitHook('HOOK_TOKEN', '', true);
//print_r($gitExec->setGitConfigDefault('autoframe','USER@gmail.com'));
//print_r($gitExec->gitCloneWithUserToken('https://github.com/autoframe/hx','USER','ghp_TOKEN'));

$gitExec = new AfrGitExec(__DIR__ . '/origin');
if ($oGhr->isPushOnMasterBranch()) {
    $aLog = $gitExec->allInOnePushCurrentThenSwitchToMasterPullAddCommitAndPush();
    echo '<pre>';
    print_r($aLog);
    echo '</pre>';
}
*/

class AfrGitHook
{
    protected string $sExceptionClass;
    protected array $aHeaders = [];
    protected string $sGitHookSecret;
    protected string $sJsonInput;
    protected array $aPayload;


    /**
     * @throws Throwable
     */
    public function __construct(
        string $sGitHookSecret = '',
        string $sExceptionClass = '\Exception',
        bool   $bDumpToFile = false,
        bool   $bSkipHeaderShaChecks = false
    )
    {
        if (!$sGitHookSecret) {
            if (defined('X_HUB_SIGNATURE')) {
                $sGitHookSecret = constant('X_HUB_SIGNATURE');
            } elseif (defined('X_HUB_SIGNATURE_256')) {
                $sGitHookSecret = constant('X_HUB_SIGNATURE_256');
            } elseif (!empty($_ENV['X_HUB_SIGNATURE'])) {
                $sGitHookSecret = $_ENV['X_HUB_SIGNATURE'];
            } elseif (!empty($_ENV['X_HUB_SIGNATURE_256'])) {
                $sGitHookSecret = $_ENV['X_HUB_SIGNATURE_256'];
            }
        }
        $this->sGitHookSecret = $sGitHookSecret;
        $this->sExceptionClass = $sExceptionClass;

        foreach (getallheaders() as $sKey => $sValue) {
            $sKey = strtolower($sKey);
            if (substr($sKey, 0, 2) == 'x-') {
                $this->aHeaders[$sKey] = $sValue;
            }
        }
        $this->sJsonInput = (string)@file_get_contents('php://input');
        $this->aPayload = $this->sJsonInput ? (array)@json_decode($this->sJsonInput, true) : $this->sJsonInput;

        if ($bDumpToFile) {
            file_put_contents(
                __DIR__ . '/x_' . $this->getEventType() . '_' . $this->getBranchName() . '_' . time() . '.log',
                print_r([
                    'HEADERS' => $this->aHeaders,
                    'EVENT' => $this->getEventType(),
                    'BRANCH' => $this->getBranchName(),
                    'DELIVERY' => $this->getEventDelivery(),
                    'isOnMasterBranch' => $this->isOnMasterBranch(),
                    'isPushOnMasterBranch' => $this->isPushOnMasterBranch(),
                    'isMergeBranch' => $this->isMergeBranch(),
                    'isMergeSameBranch' => $this->isMergeSameBranch(),
                    'isPullRequest' => $this->isPullRequest(),
                    'isPush' => $this->isPush(),
                    'isRelease' => $this->isRelease(),
                    'isFixConflict' => $this->isFixConflict(),
                    'isPing' => $this->isPing(),
                    'COMMIT_INFO' => $this->getCommitInfo(),
                    'PAYLOAD' => $this->aPayload,
                ], true));
        }
        if (!$bSkipHeaderShaChecks) {
            $this->checkHeadersAndSignature();
        }
    }


    /**
     * @param string $sMsg
     * @param int $statusCode
     * @return void
     * @throws Throwable
     */
    protected function handleException(string $sMsg, int $statusCode = 500): void
    {
        $this->replyToGithub($statusCode, $sMsg);
        if (!empty($this->sExceptionClass) && class_exists($this->sExceptionClass)) {
            throw new $this->sExceptionClass($sMsg, $statusCode);
        }
        exit(1);
    }

    /**
     * @throws Throwable
     */
    protected function checkHeadersAndSignature(): bool
    {
        if (empty($this->getEventType())) {
            $this->handleException('Missing E Header');
        }
        if (empty($this->aHeaders['x-hub-signature']) && empty($this->aHeaders['x-hub-signature-256'])) {
            $this->handleException('Missing signature');
        }

        if (empty($this->sJsonInput)) {
            $this->handleException('Post body is empty');
        } elseif (empty($this->aPayload)) {
            $this->handleException('Miss formatted body');
        }
        $sSha = !empty($this->aHeaders['x-hub-signature-256']) ? 'sha256' : 'sha1';
        $sSignature = $this->aHeaders['x-hub-signature-256'] ?? $this->aHeaders['x-hub-signature'];

        $bValidSignature = hash_equals($sSha . '=' . hash_hmac($sSha, $this->sJsonInput, $this->sGitHookSecret), $sSignature);
        if (!$bValidSignature) {
            $this->handleException('Unauthorized Signature header or Secret Key', 403);
        }
        return $bValidSignature;
    }


    public function getEventType(): string
    {
        return strtolower((string)$this->aHeaders['x-github-event'] ?? '');
    }

    public function getEventDelivery(): string
    {
        return strtolower((string)$this->aHeaders['x-github-delivery'] ?? '');
    }

    public function getPayload(): array
    {
        return $this->aPayload;
    }

    public function getHeadCommitMessage(): string
    {
        return $this->aPayload['head_commit']['message'] ?? '';
    }

    public function isMergePullRequest(): bool
    {
        return strpos($this->getHeadCommitMessage(), 'Merge pull request') !== false;
    }

    public function isMergeBranch(): bool
    {
        return strpos($this->getHeadCommitMessage(), 'Merge branch') !== false;
    }


    public function isFixConflict(): bool
    {
        return strpos($this->getHeadCommitMessage(), 'fix conflict') !== false;
    }

    public function isPush(): bool
    {
        return $this->getEventType() === 'push';
    }

    public function isRelease(): bool
    {
        return $this->getEventType() === 'release';
    }

    public function isPullRequest(): bool
    {
        return $this->getEventType() === 'pull_request';
    }

    public function isPing(): bool
    {
        return $this->getEventType() === 'ping';
    }

    public function isMergeSameBranch(): bool
    {
        if (!$this->isMergePullRequest() && !$this->isFixConflict()) {
            if (preg_match(
                '/^Merge\sbranch\s\'([\w-]+)\'.+\sinto\s([\w-]+)$/',
                $this->getHeadCommitMessage(),
                $matches
            )) {
                if ($matches[1] == $matches[2]) {
                    return true;
                }
            }
        }
        return false;
    }

    public function getCommitInfo(): array
    {
        $aCommitInfo = [];
        if (!empty($this->aPayload['commits']) && is_array($this->aPayload['commits'])) {
            foreach ($this->aPayload['commits'] as $aCommit) {
                $aCommitInfo[] = [
                    'id' => $aCommit['id'] ?? '',
                    'url' => $aCommit['url'] ?? '',
                    'author' => $aCommit['author']['name'] ?? '',
                    'message' => $aCommit['message'] ?? '',
                    'branch' => $this->getBranchName(),
                    'head_message' => $this->getHeadCommitMessage(),
                    'repo_name' => $this->aPayload['repository']['name'] ?? '',
                    'repo_full_name' => $this->aPayload['repository']['full_name'] ?? ''
                ];
            }
        }
        return $aCommitInfo;
    }

    public function getBranchName(): string
    {
        return (string)substr($this->aPayload['ref'] ?? '', strlen('refs/heads/'));
    }

    public function isOnMasterBranch(): bool
    {
        $sBranch = $this->getBranchName();
        if (strlen($sBranch) < 1) {
            return false;
        }

        if (
            !empty($this->aPayload['repository']['master_branch']) &&
            !in_array($this->aPayload['repository']['master_branch'], AfrGitExec::$defaultMasterBranchNames)) {
            AfrGitExec::$defaultMasterBranchNames = [$this->aPayload['repository']['master_branch']];
        }

        return in_array(
            $sBranch,
            AfrGitExec::$defaultMasterBranchNames
        );
    }


    public function isPushOnMasterBranch(): bool
    {
        return $this->isOnMasterBranch() && ($this->isPush() || $this->isRelease() || $this->isMergeBranch());
    }

    public function replyToGithub(int $status = 200, string $message = 'success'): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => $status, 'message' => $message]);
    }

    /**
     * $gitExec = new gitExec(__DIR__ . '/origin');
     * print_r($gitExec->setGitConfigDefault('autoframe','USER@gmail.com'));
     * print_r($gitExec->gitCloneWithUserToken('https://github.com/autoframe/hx','USER','ghp_TOKEN'));
     * @param AfrGitExec $gitExec
     * @return array
     */
    public function handlePushOnMasterBranch(AfrGitExec $gitExec): array
    {
        if ($this->isPushOnMasterBranch()) {
            return $gitExec->allInOnePushCurrentThenSwitchToMasterPullAddCommitAndPush();
        }
        return [];
    }


}

