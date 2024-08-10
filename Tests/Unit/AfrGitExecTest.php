<?php
declare(strict_types=1);

namespace Unit;

use Autoframe\GitExecHook\AfrGitExec;
use PHPUnit\Framework\TestCase;
use Throwable;

class AfrGitExecTest extends TestCase
{
    /**
     * @test
     * @throws \Exception
     */
    public function AfrGitExecTest(): void
    {
        // JUST TEST the construct, that will run and check if git is installed and git --version is called
        $oAfrGitExec = null;
        try{
            $oAfrGitExec = new AfrGitExec(__DIR__);
        }
        catch (Throwable $e){
            throw new \Exception(
                $e->getMessage().' in '.$e->getFile().' on line '.$e->getLine()
            );
        }

        $this->assertSame(true, $oAfrGitExec instanceof AfrGitExec);
    }

}