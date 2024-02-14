<?php

declare(strict_types=1);

namespace AOE\Crawler\Tests\Unit\Controller;

/*
 * (c) 2021 Tomas Norre Mikkelsen <tomasnorre@gmail.com>
 *
 * This file is part of the TYPO3 Crawler Extension.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use AOE\Crawler\Controller\CrawlerController;
use AOE\Crawler\Service\PageService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use ReflectionException;
use ReflectionMethod;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Class CrawlerLibTest
 *
 * @package AOE\Crawler\Tests
 */
#[CoversClass(CrawlerController::class)]
class CrawlerControllerTest extends UnitTestCase
{
    /**
     * @var CrawlerController
     */
    protected $crawlerController;

    /**
     * Creates the test environment.
     */
    protected function setUp(): void
    {
        $this->crawlerController = $this->createPartialMock(CrawlerController::class, []);
        $this->crawlerController->setLogger(new NullLogger());

        $configuration = [
            'sleepTime' => '1000',
            'sleepAfterFinish' => '10',
            'countInARun' => '100',
            'purgeQueueDays' => '14',
            'processLimit' => '1',
            'processMaxRunTime' => '300',
            'maxCompileUrls' => '10000',
            'processDebug' => '0',
            'processVerbose' => '0',
            'crawlHiddenPages' => '0',
            'phpPath' => '/usr/bin/php',
            'enableTimeslot' => '1',
            'makeDirectRequests' => '0',
            'frontendBasePath' => '/',
            'cleanUpOldQueueEntries' => '1',
            'cleanUpProcessedAge' => '2',
            'cleanUpScheduledAge' => '7',
        ];
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['crawler'] = $configuration;
    }

    /**
     * Resets the test environment after the test.
     */
    protected function tearDown(): void
    {
        unset($this->crawlerController);
    }


    #[DataProvider('drawURLs_PIfilterDataProvider')]
    #[Test]
    public function drawURLsPIfilter(string $piString, array $incomingProcInstructions, bool $expected): void
    {
        self::assertEquals(
            $expected,
            $this->crawlerController->drawURLs_PIfilter($piString, $incomingProcInstructions)
        );
    }

    #[Test]
    public function getUrlsForPageRowSetsSkipMessageIfUidNotAnInteger(): void
    {
        $skipMessage = '';
        $this->crawlerController->getUrlsForPageRow(['uid' => 'string'], $skipMessage);
        self::assertEquals('PageUid "string" was not an integer', $skipMessage);
    }


    #[DataProvider('getUrlsForPageRowDataProvider')]
    #[Test]
    public function getUrlsForPageRow(
        bool $checkIfPageSkipped,
        array $getUrlsForPages,
        array $pageRow,
        string $skipMessage,
        array $expected
    ): void {
        $mockedPageService = $this->createPartialMock(PageService::class, ['checkIfPageShouldBeSkipped']);
        if ($checkIfPageSkipped) {
            $mockedPageService->expects($this->any())->method('checkIfPageShouldBeSkipped')->will(
                $this->returnValue($skipMessage)
            );
        } else {
            $mockedPageService->expects($this->any())->method('checkIfPageShouldBeSkipped')->will(
                $this->returnValue($checkIfPageSkipped)
            );
        }

        /** @var MockObject|CrawlerController $crawlerController */
        $crawlerController = $this->createPartialMock(CrawlerController::class, ['getPageService', 'getUrlsForPageId']);
        $crawlerController->expects($this->any())->method('getPageService')->will(
            $this->returnValue($mockedPageService)
        );
        $crawlerController->expects($this->any())->method('getUrlsForPageId')->will(
            $this->returnValue($getUrlsForPages)
        );

        self::assertEquals($expected, $crawlerController->getUrlsForPageRow($pageRow, $skipMessage));
    }

    public static function getUrlsForPageRowDataProvider(): iterable
    {
        yield 'Message equals false, returns Urls from getUrlsForPages()' => [
            'checkIfPageSkipped' => false,
            'getUrlsForPages' => ['index.php?q=search&page=1', 'index.php?q=search&page=2'],
            'pageRow' => ['uid' => 2001],
            '$skipMessage' => 'Just variable placeholder, not used in tests as parsed as reference',
            'expected' => ['index.php?q=search&page=1', 'index.php?q=search&page=2'],
        ];
        yield 'Message string not empty, returns empty array' => [
            'checkIfPageSkipped' => true,
            'getUrlsForPages' => ['index.php?q=search&page=1', 'index.php?q=search&page=2'],
            'pageRow' => ['uid' => 2001],
            '$skipMessage' => 'Just variable placeholder, not used in tests as parsed as reference',
            'expected' => [],
        ];
        yield 'PageRow Uid is string with int value' => [
            'checkIfPageSkipped' => false,
            'getUrlsForPages' => ['index.php?q=search&page=1', 'index.php?q=search&page=2'],
            'pageRow' => ['uid' => '2001'],
            '$skipMessage' => 'Just variable placeholder, not used in tests as parsed as reference',
            'expected' => ['index.php?q=search&page=1', 'index.php?q=search&page=2'],
        ];
        yield 'PageRow Uid is string with string value' => [
            'checkIfPageSkipped' => true,
            'getUrlsForPages' => ['index.php?q=search&page=1', 'index.php?q=search&page=2'],
            'pageRow' => ['uid' => 'string'],
            '$skipMessage' => 'PageUid "string" was not an integer',
            'expected' => [],
        ];
    }

    public function getConfigurationKeysDataProvider(): iterable
    {
        yield 'cliObject with no -conf' => [
            'config' => ['-d' => 4, '-o' => 'url'],
            'expected' => [],
        ];
        yield 'cliObject with one -conf' => [
            'config' => ['-d' => 4, '-o' => 'url', '-conf' => 'default'],
            'expected' => ['default'],
        ];
        yield 'cliObject with two -conf' => [
            'config' => ['-d' => 4, '-o' => 'url', '-conf' => 'default,news'],
            'expected' => ['default', 'news'],
        ];
    }

    public static function drawURLs_PIfilterDataProvider(): iterable
    {
        yield 'Not in list' => [
            'piString' => 'tx_indexedsearch_reindex,tx_esetcache_clean_main',
            'incomingProcInstructions' => ['tx_unknown_extension_instruction'],
            'expected' => false,
        ];
        yield 'In list' => [
            'piString' => 'tx_indexedsearch_reindex,tx_esetcache_clean_main',
            'incomingProcInstructions' => ['tx_indexedsearch_reindex'],
            'expected' => true,
        ];
        yield 'Twice in list' => [
            'piString' => 'tx_indexedsearch_reindex,tx_esetcache_clean_main',
            'incomingProcInstructions' => ['tx_indexedsearch_reindex', 'tx_indexedsearch_reindex'],
            'expected' => true,
        ];
        yield 'Empty incomingProcInstructions' => [
            'piString' => '',
            'incomingProcInstructions' => [],
            'expected' => true,
        ];
        yield 'In list CAPITALIZED' => [
            'piString' => 'tx_indexedsearch_reindex,tx_esetcache_clean_main',
            'incomingProcInstructions' => ['TX_INDEXEDSEARCH_REINDES'],
            'expected' => false,
        ];
    }

    #[Test]
    public function setExtensionSettings(): void
    {
        $extensionSettings = [
            'makeDirectRequests' => 0,
            'frontendBasePath' => '/',
        ];

        /** @var CrawlerController $crawlerController */
        $crawlerController = $this->createPartialMock(CrawlerController::class, []);
        $crawlerController->setExtensionSettings($extensionSettings);
        self::assertEquals($extensionSettings, $crawlerController->extensionSettings);
    }
}
