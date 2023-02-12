<?php

/**
 * @noinspection ForgottenDebugOutputInspection
 */
declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\Panther\Client as Panther;
use GuzzleHttp\Client as Guzzle;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use function BenTools\StringCombinations\string_combinations;

require_once __DIR__ . '/vendor/autoload.php';

$logger = new Logger('lolo-logger');
$logger->pushHandler(new StreamHandler(__DIR__ . '/log.log'));

/** @var EntityManagerInterface $doctrine */
$doctrine = require __DIR__ . '/doctrine.php';

try {
    waitBrowserStart();

    $panther = Panther::createSeleniumClient('lolo-firefox:4444', DesiredCapabilities::firefox());

    $indexPage = $panther->request('GET', 'https://lolopizza.ru/sevastopol');
    $indexPage->findElement(WebDriverBy::xpath('/html/body/div[1]/div[4]/div[1]/form/div[5]/button'))->click();

    $basket = $panther->request('GET', 'https://lolopizza.ru/basket');
    $order = $panther->request('GET', 'https://lolopizza.ru/order');

    $customerPhone = $order->findElement(WebDriverBy::name('customer_phone'));
    $customerPhone->click()->sendKeys('9')
        ->click()->sendKeys('7')
        ->click()->sendKeys('8')
        ->click()->sendKeys('1')
        ->click()->sendKeys('6')
        ->click()->sendKeys('4')
        ->click()->sendKeys('9')
        ->click()->sendKeys('7')
        ->click()->sendKeys('7')
        ->click()->sendKeys('5');

    $frontendSessionId = $panther->getCookieJar()->get('FRONTENDSESSID')->getValue();
    $csrfFrontend = $panther->getCookieJar()->get('_csrf-frontend')->getValue();
    $xCsrfToken = $order->findElement(WebDriverBy::name('csrf-token'))->getAttribute('content');

    $panther->quit();

    $logger->debug('lolo-promo.page.values', [
        'xCsrfToken'        => $xCsrfToken,
        'frontendSessionId' => $frontendSessionId,
        'csrfFrontend'      => $csrfFrontend,
    ]);

    $guzzle = new Guzzle([
        'headers' => [
            'cookie'           => \sprintf('_csrf-frontend=%s; FRONTENDSESSID=%s', $csrfFrontend, $frontendSessionId),
            'x-requested-with' => 'XMLHttpRequest',
            'x-csrf-token'     => $xCsrfToken,
        ]
    ]);

    $lastCode = getLastCode();
    $founded = $lastCode === null;

    foreach (string_combinations('0123456789', 1, 4) as $code) {
        if (!$founded) {
            if ($code === $lastCode) {
                $founded = true;
            }

            continue;
        }

        $promoResponse = $guzzle->post('https://lolopizza.ru/order/promo', [
            'form_params' => [
                'promo' => $code,
            ]
        ]);

        $checkResponse = $guzzle->post('https://lolopizza.ru/order/check', [
            'form_params' => [
                'check'       => 'check',
                'bonus'       => 'false',
                'bonus_val'   => '-1',
                'getProducts' => '1',
            ]
        ]);

        $content = $checkResponse->getBody()->getContents();

        try {
            $products = \json_decode($content, true, 512, JSON_THROW_ON_ERROR)['list'];
        } catch (Throwable $throwable) {
            $logger->critical('lolo-promo.json.decode.error', [
                'content' => $content,
            ]);

            saveLastCode($code);
            continue;
        }

        if (is_array($products)) {
            $result = array_filter($products, static function (array $product) {
                return $product['title'] !== 'Бесплатная доставка';
            });

            if (count($result) !== 0 && !isCodeExists($code, $doctrine)) {
                dump($code);
                dump($result);

                $logger->info('lolo-promo.founded.new.code', ['code' => $code, 'result' => $products,]);
                addCode($code, $content, $doctrine);
            }
        } else {
            $logger->notice('lolo-promo.not.array.products', ['value' => $products,]);
        }

        saveLastCode($code);
    }

    $logger->debug('lolo-promo.finished');
} catch (Throwable $throwable) {
    dump($throwable);
    $logger->critical('lolo-promo.critical', ['throwable' => $throwable]);
} finally {
    if (isset($panther) && $panther instanceof \Symfony\Component\BrowserKit\AbstractBrowser) {
        $panther->quit();
    }
}

function waitBrowserStart(): void
{
    $ready = false;

    while (!$ready) {
        $ready = @file_get_contents('http://lolo-firefox:4444/wd/hub/status') !== false;
    }
}

function isCodeExists(string $code, EntityManagerInterface $doctrine): bool
{
    return $doctrine->getConnection()->createQueryBuilder()
            ->select('1')
            ->from('promo_codes')
            ->where('code = :code')
            ->setParameter('code', $code)
            ->executeQuery()
            ->rowCount() !== 0;
}

function addCode(string $code, string $description, EntityManagerInterface $doctrine): void
{
    $doctrine->getConnection()->createQueryBuilder()
        ->insert('promo_codes')
        ->values(['code' => ':code', 'description' => ':description'])
        ->setParameter('code', $code)
        ->setParameter('description', $description)
        ->executeQuery();
}

function saveLastCode(string $code): void
{
    file_put_contents(__DIR__ . '/lastCode.txt', $code);
}

function getLastCode(): ?string
{
    if (!file_exists(__DIR__ . '/lastCode.txt')) {
        return null;
    }

    return trim(file_get_contents(__DIR__ . '/lastCode.txt'));
}
