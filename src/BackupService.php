<?php

declare(strict_types=1);

namespace App;

use TomasKulhanek\CzechDataBox\Account;
use TomasKulhanek\CzechDataBox\Connector;
use TomasKulhanek\CzechDataBox\DTO\MessageRecord;
use TomasKulhanek\CzechDataBox\DTO\Request\GetListOfReceivedMessages;
use TomasKulhanek\CzechDataBox\DTO\Request\GetListOfSentMessages;
use TomasKulhanek\CzechDataBox\DTO\Request\MessageDownload;
use TomasKulhanek\CzechDataBox\Enum\FilterEnum;
use TomasKulhanek\CzechDataBox\Enum\LoginTypeEnum;

/**
 * Core backup logic for Czech Data Box (ISDS) messages.
 *
 * Downloads received and sent messages from a databox account,
 * saving envelope metadata as JSON and attachments as individual files.
 */
final class BackupService
{
    private const PAGE_LIMIT = 1000;

    public function __construct(
        private readonly Connector $connector,
        private readonly string $dataDir,
    ) {
    }

    /**
     * Backs up all messages (received and sent) for the given databox.
     *
     * @param array{id: string, login: string, password: string} $databox
     */
    public function backup(array $databox, bool $production): void
    {
        $account = $this->createAccount($databox, $production);
        $boxId = $databox['id'];

        echo sprintf("\n=== Backing up databox: %s ===\n", $boxId);

        $this->backupMessages($account, $boxId, 'received');
        $this->backupMessages($account, $boxId, 'sent');
    }

    /**
     * Creates an Account instance configured for login.
     *
     * @param array{id: string, login: string, password: string} $databox
     */
    private function createAccount(array $databox, bool $production): Account
    {
        $account = new Account();
        $account->setLoginName($databox['login'])
            ->setPassword($databox['password'])
            ->setLoginType(LoginTypeEnum::NAME_PASSWORD);
        $account->setProduction($production);

        return $account;
    }

    /**
     * Iterates through all messages in one direction (received/sent) and downloads them.
     */
    private function backupMessages(Account $account, string $boxId, string $direction): void
    {
        echo sprintf("\n--- %s messages ---\n", ucfirst($direction));

        $offset = 0;
        $downloaded = 0;
        $skipped = 0;
        $errors = 0;

        while (true) {
            $records = $this->fetchMessageList($account, $direction, $offset);

            if (count($records) === 0) {
                break;
            }

            foreach ($records as $record) {
                $messageId = $record->getDataMessageId();

                if ($messageId === null) {
                    $errors++;
                    echo "  ERROR: Message record with null ID, skipping\n";
                    continue;
                }

                $date = $record->getDeliveryTime() ?? $record->getAcceptanceTime();

                if ($date === null) {
                    $date = new \DateTimeImmutable();
                }

                $targetDir = $this->buildTargetDir($boxId, $direction, $date, $messageId);

                if (is_dir($targetDir)) {
                    $skipped++;
                    continue;
                }

                try {
                    $this->downloadAndSave($account, $messageId, $targetDir, $record);
                    $downloaded++;
                    echo sprintf("  Downloaded: %s (%s)\n", $messageId, $record->getAnnotation() ?? 'no subject');
                } catch (\Throwable $e) {
                    $errors++;
                    echo sprintf("  ERROR [%s]: %s\n", $messageId, $e->getMessage());
                }
            }

            if (count($records) < self::PAGE_LIMIT) {
                break;
            }

            $offset += self::PAGE_LIMIT;
        }

        echo sprintf("  Total: %d downloaded, %d skipped, %d errors\n", $downloaded, $skipped, $errors);
    }

    /**
     * Fetches a page of message records from the ISDS API.
     *
     * @return array<MessageRecord>
     */
    private function fetchMessageList(Account $account, string $direction, int $offset): array
    {
        $from = new \DateTimeImmutable('2009-07-01');
        $to = new \DateTimeImmutable('now');

        if ($direction === 'received') {
            $request = new GetListOfReceivedMessages();
            $request->setListFrom($from)
                ->setListTo($to)
                ->setStatusFilter(FilterEnum::ALL)
                ->setOffset($offset)
                ->setLimit(self::PAGE_LIMIT);

            $response = $this->connector->getListOfReceivedMessages($account, $request);
        } else {
            $request = new GetListOfSentMessages();
            $request->setListFrom($from)
                ->setListTo($to)
                ->setStatusFilter(FilterEnum::ALL)
                ->setOffset($offset)
                ->setLimit(self::PAGE_LIMIT);

            $response = $this->connector->getListOfSentMessages($account, $request);
        }

        return $response->getRecord();
    }

    /**
     * Builds the directory path for storing a single message.
     *
     * Structure: {dataDir}/{boxId}/{direction}/{year}/{month}/{YYYYMMDD}_{messageId}
     */
    private function buildTargetDir(
        string $boxId,
        string $direction,
        \DateTimeImmutable $date,
        string $messageId,
    ): string {
        return sprintf(
            '%s/%s/%s/%s/%s/%s_%s',
            $this->dataDir,
            $boxId,
            $direction,
            $date->format('Y'),
            $date->format('m'),
            $date->format('Ymd'),
            $messageId,
        );
    }

    /**
     * Downloads a single message and saves its envelope metadata and attachments.
     *
     * @throws \RuntimeException When message data cannot be retrieved or directory cannot be created
     */
    private function downloadAndSave(
        Account $account,
        string $messageId,
        string $targetDir,
        MessageRecord $record,
    ): void {
        $downloadRequest = new MessageDownload();
        $downloadRequest->setDataMessageId($messageId);
        $downloadResponse = $this->connector->messageDownload($account, $downloadRequest);

        $message = $downloadResponse->getReturnedMessage();
        if ($message === null) {
            throw new \RuntimeException('No message data returned');
        }

        if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException(sprintf('Failed to create directory: %s', $targetDir));
        }

        // Save envelope metadata as JSON
        $envelope = [
            'messageId' => $messageId,
            'sender' => $record->getSender(),
            'senderId' => $record->getSenderId(),
            'recipient' => $record->getRecipient(),
            'recipientId' => $record->getRecipientId(),
            'subject' => $record->getAnnotation(),
            'deliveryTime' => $record->getDeliveryTime()?->format('c'),
            'acceptanceTime' => $record->getAcceptanceTime()?->format('c'),
            'status' => $record->getMessageStatus(),
            'attachmentSize' => $record->getAttachmentSize(),
        ];
        file_put_contents(
            $targetDir . '/envelope.json',
            json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );

        // Save attachments from the message envelope
        $dataMessage = $message->getDataMessage();
        $files = $dataMessage->getFiles();

        foreach ($files as $file) {
            $filename = $file->getDescription();
            if (empty($filename)) {
                $filename = 'attachment_' . ($file->getGuid() ?? uniqid());
            }

            // Sanitize filename: keep word characters, hyphens, dots, and spaces
            $filename = preg_replace('/[^\w\-. ]/', '_', $filename);
            if ($filename === null || $filename === '') {
                $filename = 'attachment_' . uniqid();
            }

            $content = $file->getEncodedContent();
            if ($content !== null) {
                // SplFileInfo::getContents() returns already-decoded binary data
                file_put_contents(
                    $targetDir . '/' . $filename,
                    $content->getContents(),
                );
            }
        }
    }
}
