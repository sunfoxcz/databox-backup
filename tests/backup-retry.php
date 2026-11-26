<?php

declare(strict_types=1);

use App\BackupService;
use TomasKulhanek\CzechDataBox\Account;
use TomasKulhanek\CzechDataBox\Connector;
use TomasKulhanek\CzechDataBox\DTO\File;
use TomasKulhanek\CzechDataBox\DTO\MessageEnvelope;
use TomasKulhanek\CzechDataBox\DTO\MessageRecord;
use TomasKulhanek\CzechDataBox\DTO\Request;
use TomasKulhanek\CzechDataBox\DTO\Response;
use TomasKulhanek\CzechDataBox\DTO\ReturnedMessage;
use TomasKulhanek\Serializer\Utils\SplFileInfo;

require dirname(__DIR__) . '/vendor/autoload.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function record(string $id): MessageRecord
{
    return (new MessageRecord())
        ->setDataMessageId($id)
        ->setDeliveryTime(new DateTimeImmutable('2026-01-02'))
        ->setSender('Test sender')
        ->setSenderId('sender')
        ->setRecipient('Test recipient')
        ->setAnnotation('Test message')
        ->setMessageStatus(1)
        ->setAttachmentSize(1);
}

final readonly class RetryConnector extends Connector
{
    public function __construct(private string $signature, public stdClass $attempts)
    {
    }

    public function getListOfReceivedMessages(
        Account $account,
        Request\GetListOfReceivedMessages $input,
    ): Response\GetListOfReceivedMessages {
        return new class extends Response\GetListOfReceivedMessages {
            public function getRecord(): array
            {
                return [record('101')];
            }
        };
    }

    public function getListOfSentMessages(
        Account $account,
        Request\GetListOfSentMessages $input,
    ): Response\GetListOfSentMessages {
        return new class extends Response\GetListOfSentMessages {
            public function getRecord(): array
            {
                return [record('102')];
            }
        };
    }

    public function messageDownload(Account $account, Request\MessageDownload $input): Response\MessageDownload
    {
        $attachment = (new File())->setDescription('envelope.json')->setXmlContent('<test/>');
        $second = (new File())->setDescription('empty.txt');
        if (++$this->attempts->received > 1) {
            $second->setXmlContent('');
        }

        $message = (new ReturnedMessage())
            ->setDataMessage((new MessageEnvelope())->setFiles([$attachment, $second]));

        return (new Response\MessageDownload())->setReturnedMessage($message);
    }

    public function signedSentMessageDownload(
        Account $account,
        Request\SignedSentMessageDownload $input,
    ): Response\SignedSentMessageDownload {
        $response = new Response\SignedSentMessageDownload();
        if (++$this->attempts->sent > 1) {
            $response->setSignature(new SplFileInfo($this->signature));
        }

        return $response;
    }
}

$directory = sys_get_temp_dir() . '/databox-backup-test-' . bin2hex(random_bytes(8));
expect(mkdir($directory, 0700), 'Could not create temporary test directory');
try {
    $config = ['id' => 'test', 'login' => 'test', 'password' => 'test'];
    $log = $directory . '/errors.log';
    $signature = $directory . '/test.zfo';
    file_put_contents($signature, 'synthetic signed payload');
    $attempts = (object) ['received' => 0, 'sent' => 0];
    $service = new BackupService(new RetryConnector($signature, $attempts), $directory, $log);
    $received = $directory . '/test/received/2026/01/20260102_101';
    $sent = $directory . '/test/sent/2026/01/20260102_102';

    $service->backup($config, false);
    expect(!file_exists($sent), 'A failed sent message was published');
    expect(!file_exists($received), 'A failed received message was published');
    $errors = file_get_contents($log);
    expect(str_contains($errors, '/received/101:'), 'The received failure was not logged');
    expect(str_contains($errors, '/sent/102:'), 'The sent failure was not logged');
    foreach ([$received, $sent] as $target) {
        expect(glob(dirname($target) . '/.partial-*') === [], 'Failed staging directory was left behind');
    }

    $service->backup($config, false);
    $envelope = json_decode(file_get_contents($received . '/envelope.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($envelope['messageId'] === '101', 'The envelope was not saved or an attachment overwrote it');
    expect(file_get_contents($received . '/envelope_1.json') === '<test/>', 'The received attachment was not saved');
    expect(file_get_contents($received . '/empty.txt') === '', 'The empty received attachment was not saved');
    expect(file_get_contents($sent . '/message.zfo') === 'synthetic signed payload', 'The sent signature was not saved');

    $service->backup($config, false);
    expect($attempts->received === 2 && $attempts->sent === 2, 'Complete messages should not be re-downloaded');

    echo "Backup retry regression passed.\n";
} finally {
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}
