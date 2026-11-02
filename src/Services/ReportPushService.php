<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use DateTimeImmutable;
use DateTimeZone;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Data\HealthReportData;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Renderers\JsonReportRenderer;

/**
 * Sends a report to the user's own `excubia-app` (`docs/SPEC.md` section 13).
 *
 * The envelope (`docs/push-schema.json`) carries a fresh `batchId`, so a
 * send the HTTP client retries is stored once by the app. The token goes
 * in the `Authorization` header only; it is never part of a message,
 * the URL or the payload.
 */
final class ReportPushService
{
    public const PROTOCOL_VERSION = 1;

    public const REPORTS_PATH = '/api/reports';

    public function __construct(private readonly HttpClientContract $httpClient) {}

    /**
     * @return string the batch id that was sent
     *
     * @throws InvalidConfigurationException when the URL or token is missing, or the URL is not HTTPS
     * @throws DataSourceUnavailableException when the app rejects the token or does not accept the report
     */
    public function push(
        HealthReportData $report,
        ?string $appUrl,
        #[\SensitiveParameter]
        ?string $appToken,
        DateTimeImmutable $sentAt,
    ): string {
        if ($appUrl === null || $appUrl === '') {
            throw new InvalidConfigurationException('No excubia-app URL. Set EXCUBIA_APP_URL or app.url in excubia.json.');
        }

        if ($appToken === null || $appToken === '') {
            throw new InvalidConfigurationException('No project token. Set EXCUBIA_APP_TOKEN or app.token in excubia.json.');
        }

        if (! SymfonyHttpClientService::isSecureUrl($appUrl)) {
            throw new InvalidConfigurationException('The excubia-app URL must use HTTPS (plain HTTP only for localhost).');
        }

        $endpoint = rtrim($appUrl, '/').self::REPORTS_PATH;
        $batchId = $this->batchId();

        try {
            $this->httpClient->postJson($endpoint, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'batchId' => $batchId,
                'sentAt' => $sentAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'report' => (new JsonReportRenderer)->toArray($report),
            ], ['Authorization' => 'Bearer '.$appToken]);
        } catch (DataSourceUnavailableException $exception) {
            throw new DataSourceUnavailableException('excubia-app', $this->failureMessage($endpoint, $exception), $exception, $exception->httpStatus);
        }

        return $batchId;
    }

    private function failureMessage(string $endpoint, DataSourceUnavailableException $exception): string
    {
        if ($exception->httpStatus === 401 || $exception->httpStatus === 403) {
            return 'excubia-app rejected the project token (HTTP '.$exception->httpStatus.'). Check EXCUBIA_APP_TOKEN or app.token.';
        }

        if ($exception->httpStatus !== null) {
            return 'excubia-app did not accept the report (HTTP '.$exception->httpStatus.').';
        }

        return 'excubia-app could not be reached at '.$endpoint.': '.$exception->getMessage();
    }

    /**
     * Random UUID version 4.
     */
    private function batchId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
