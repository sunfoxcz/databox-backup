<?php

declare(strict_types=1);

namespace App;

use TomasKulhanek\CzechDataBox\Account;
use TomasKulhanek\CzechDataBox\Connector;
use TomasKulhanek\CzechDataBox\DTO\MessageRecord;
use TomasKulhanek\CzechDataBox\DTO\Request\GetListOfReceivedMessages;
use TomasKulhanek\CzechDataBox\DTO\Request\GetListOfSentMessages;
use TomasKulhanek\CzechDataBox\DTO\Request\MessageDownload;
use TomasKulhanek\CzechDataBox\DTO\Request\SignedSentMessageDownload;
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
        private readonly string $logFile,
        private readonly bool $verbose = false,
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

        $this->log(sprintf("\n\033[1;36m>>> Backing up databox: %s\033[0m\n", $boxId));

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
        $this->log(sprintf("\n\033[1m--- %s messages ---\033[0m\n", ucfirst($direction)));

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
                    $this->logError($boxId, $direction, 'null', 'Message record with null ID, skipping');
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
                    $this->downloadAndSave($account, $messageId, $targetDir, $record, $direction);
                    $downloaded++;
                    $this->log(sprintf("  \033[32m+\033[0m %s \033[2m(%s)\033[0m\n", $messageId, $record->getAnnotation() ?? 'no subject'));
                } catch (\Throwable $e) {
                    $errors++;
                    $this->logError($boxId, $direction, $messageId, $e->getMessage());
                }
            }

            if (count($records) < self::PAGE_LIMIT) {
                break;
            }

            $offset += self::PAGE_LIMIT;
        }

        $summary = sprintf(
            "  \033[32m%d downloaded\033[0m, \033[33m%d skipped\033[0m",
            $downloaded,
            $skipped,
        );
        if ($errors > 0) {
            $summary .= sprintf(", \033[31m%d errors\033[0m", $errors);
        }
        $this->log($summary . "\n");
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
     * For received messages: downloads full message with individual attachments.
     * For sent messages: ISDS only provides signed download (SignedSentMessageDownload),
     * which returns a PKCS#7 signed blob saved as message.zfo.
     *
     * @throws \RuntimeException When message data cannot be retrieved or directory cannot be created
     */
    private function downloadAndSave(
        Account $account,
        string $messageId,
        string $targetDir,
        MessageRecord $record,
        string $direction,
    ): void {
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

        if ($direction === 'sent') {
            $this->downloadSentMessage($account, $messageId, $targetDir);
        } else {
            $this->downloadReceivedMessage($account, $messageId, $targetDir);
        }
    }

    /**
     * Downloads a received message and saves individual attachments.
     */
    private function downloadReceivedMessage(Account $account, string $messageId, string $targetDir): void
    {
        $downloadRequest = new MessageDownload();
        $downloadRequest->setDataMessageId($messageId);
        $downloadResponse = $this->connector->messageDownload($account, $downloadRequest);

        $message = $downloadResponse->getReturnedMessage();
        if ($message === null) {
            throw new \RuntimeException('No message data returned');
        }

        $dataMessage = $message->getDataMessage();
        $files = $dataMessage->getFiles();
        $usedFilenames = [];

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

            // Deduplicate filenames to avoid overwriting
            $originalFilename = $filename;
            $counter = 1;
            while (isset($usedFilenames[$filename])) {
                $pathInfo = pathinfo($originalFilename);
                $filename = ($pathInfo['filename'] ?? 'file') . '_' . $counter;
                if (isset($pathInfo['extension'])) {
                    $filename .= '.' . $pathInfo['extension'];
                }
                $counter++;
            }
            $usedFilenames[$filename] = true;

            $content = $file->getEncodedContent();
            if ($content !== null) {
                file_put_contents(
                    $targetDir . '/' . $filename,
                    $content->getContents(),
                );
            } elseif ($file->getXmlContent() !== null) {
                file_put_contents(
                    $targetDir . '/' . $filename,
                    $file->getXmlContent(),
                );
            }
        }
    }

    /**
     * Downloads a sent message as a signed .zfo file.
     *
     * ISDS does not support MessageDownload for sent messages — only SignedSentMessageDownload,
     * which returns the entire message as a PKCS#7 signed blob.
     */
    private function downloadSentMessage(Account $account, string $messageId, string $targetDir): void
    {
        $downloadRequest = new SignedSentMessageDownload();
        $downloadRequest->setDataMessageId($messageId);
        $downloadResponse = $this->connector->signedSentMessageDownload($account, $downloadRequest);

        $signature = $downloadResponse->getSignature();
        if ($signature === null) {
            throw new \RuntimeException('No signed message data returned');
        }

        file_put_contents(
            $targetDir . '/message.zfo',
            $signature->getContents(),
        );
    }

    private function log(string $message): void
    {
        if ($this->verbose) {
            echo $message;
        }
    }

    private function logError(string $boxId, string $direction, string $messageId, string $message): void
    {
        $line = sprintf(
            "[%s] %s/%s/%s: %s\n",
            date('Y-m-d H:i:s'),
            $boxId,
            $direction,
            $messageId,
            $message,
        );

        fwrite(STDERR, sprintf("  \033[31mERROR [%s]:\033[0m %s\n", $messageId, $message));
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }
}
