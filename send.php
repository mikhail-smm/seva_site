<?php
declare(strict_types=1);

/**
 * send.php
 *
 * Обработчик заявок с сайта:
 * HTML-форма → PHP → Email + Telegram
 *
 * Логика приоритетов:
 * - Email = основной канал (mail())
 * - Telegram = дополнительный канал
 * - Если email отправился → всегда возвращаем success, даже если Telegram упал
 * - Если email не ушёл, но Telegram ушёл → тоже success
 * - Ошибка пользователю только если не ушло никуда
 */

// =============================================================================
// НАСТРОЙКИ — редактировать только этот блок
// =============================================================================

// Telegram: вставьте токен бота от @BotFather
$telegramBotToken = '8769088163:AAFX-D6bRR9T1OBhSnjJNYrP7sRfVNEfy1A';
$telegramChatId   = '242202478';

// SOCKS5-прокси для запросов к Telegram API (оставить пустым, если не нужна)
$telegramProxy = 'socks5://proxy_user:GsRc2u1Mmym2iUF9@176.12.77.20:45561';

// Email: получатель заявок
$emailTo      = 'lead@simakova-studio.ru';

// Email: отправитель (должен совпадать с доменом хостинга для надёжности)
$emailFrom    = 'hello@simakova-studio.ru';

// Email: адрес для Reply-To (куда пойдёт ответ менеджера)
$emailReplyTo = 'ev@simakova-studio.ru';

// Email: тема письма
$emailSubject = 'Новая заявка с сайта simakova-studio.ru';

// Файл для логов ошибок (создаётся автоматически)
$logFile = __DIR__ . '/send_error.log';

// Таймаут запроса к Telegram API (секунды)
$requestTimeout = 10;

// =============================================================================
// БАЗОВАЯ ПРОВЕРКА МЕТОДА
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Метод не поддерживается.'], 405);
}

// =============================================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// =============================================================================

/**
 * Очищает входную строку: trim + схлопывает пробелы.
 */
function cleanInput(?string $value): string
{
    $value = $value ?? '';
    $value = trim($value);
    return preg_replace('/\s+/u', ' ', $value) ?? '';
}

/**
 * Нормализует телефон (убирает лишние пробелы, оставляет символы как есть).
 */
function normalizePhone(string $phone): string
{
    return preg_replace('/\s+/u', ' ', trim($phone)) ?? '';
}

/**
 * Пишет строку в лог-файл с временной меткой.
 */
function logError(string $logFile, string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND);
}

/**
 * Отправляет JSON-ответ и завершает скрипт.
 */
function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Экранирует HTML-спецсимволы (для Telegram HTML parse_mode).
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// =============================================================================
// TELEGRAM
// =============================================================================

/**
 * Собирает текст сообщения для Telegram (HTML-разметка).
 */
function buildTelegramMessage(array $data): string
{
    $lines = [];
    $lines[] = '<b>Новая заявка с сайта</b>';

    if (!empty($data['lead_source']))   $lines[] = 'Источник: '                . e($data['lead_source']);
    if (!empty($data['name']))          $lines[] = 'Имя: '                     . e($data['name']);
    if (!empty($data['phone']))         $lines[] = 'Телефон: '                 . e($data['phone']);
    if (!empty($data['object_type']))   $lines[] = 'Тип объекта: '             . e($data['object_type']);
    if (!empty($data['work_type']))     $lines[] = 'Формат работ: '            . e($data['work_type']);
    if (!empty($data['area']))          $lines[] = 'Площадь: '                 . e($data['area']) . ' м²';
    if (!empty($data['rooms']))         $lines[] = 'Комнат: '                  . e($data['rooms']);
    if (!empty($data['district']))      $lines[] = 'Район / ЖК: '             . e($data['district']);
    if (!empty($data['planned_start'])) $lines[] = 'Когда планируют начать: ' . e($data['planned_start']);

    return implode("\n", $lines);
}

/**
 * Отправляет сообщение в Telegram.
 * Использует cURL если доступен, иначе file_get_contents.
 * Возвращает false без фатальной ошибки, если Telegram не настроен или недоступен.
 * Если задан $proxy (socks5://user:pass@host:port), запрос идёт через SOCKS5.
 */
function sendTelegramMessage(
    string $botToken,
    string $chatId,
    string $text,
    int    $timeout,
    string $logFile,
    string $proxy = ''
): bool {
    // Пропускаем без ошибки, если Telegram не настроен
    if ($botToken === '' || $chatId === ''
        || $botToken === 'PASTE_YOUR_BOT_TOKEN_HERE'
        || $chatId   === 'PASTE_YOUR_CHAT_ID_HERE') {
        logError($logFile, 'Telegram пропущен: token/chat_id не заполнены.');
        return false;
    }

    $url      = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $postData = [
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ];

    // --- cURL (предпочтительный способ) ---
    if (function_exists('curl_init')) {
        $ch = curl_init($url);

        $curlOpts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        // Подключаем SOCKS5-прокси, если задана
        if ($proxy !== '') {
            $parsed = @parse_url($proxy);
            if (!$parsed || empty($parsed['host']) || empty($parsed['port'])) {
                logError($logFile, 'Telegram: не удалось распарсить строку прокси: ' . $proxy);
            } else {
                $curlOpts[CURLOPT_PROXY]     = $parsed['host'];
                $curlOpts[CURLOPT_PROXYPORT] = (int) $parsed['port'];
                $curlOpts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
                if (!empty($parsed['user']) && !empty($parsed['pass'])) {
                    $curlOpts[CURLOPT_PROXYUSERPWD] =
                        urldecode($parsed['user']) . ':' . urldecode($parsed['pass']);
                }
            }
        }

        curl_setopt_array($ch, $curlOpts);

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            logError($logFile, 'Telegram cURL error: ' . $curlError);
            return false;
        }
        if ($httpCode !== 200) {
            logError($logFile, "Telegram HTTP {$httpCode}. Response: {$response}");
            return false;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            logError($logFile, 'Telegram API error: ' . $response);
            return false;
        }

        return true;
    }

    // --- Fallback: file_get_contents (прокси не поддерживается, идёт напрямую) ---
    if ($proxy !== '') {
        logError($logFile, 'Telegram: cURL недоступен, прокси не используется (fallback на file_get_contents).');
    }

    $context  = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($postData),
            'timeout' => $timeout,
        ]
    ]);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        logError($logFile, 'Telegram file_get_contents error.');
        return false;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        logError($logFile, 'Telegram API error: ' . $response);
        return false;
    }

    return true;
}

// =============================================================================
// EMAIL
// =============================================================================

/**
 * Собирает текст письма (plain text, UTF-8).
 */
function buildEmailBody(array $data): string
{
    $lines = [];
    $lines[] = 'Новая заявка с сайта simakova-studio.ru';
    $lines[] = str_repeat('-', 40);

    if (!empty($data['lead_source']))   $lines[] = "Источник:                {$data['lead_source']}";
    if (!empty($data['name']))          $lines[] = "Имя:                     {$data['name']}";
    if (!empty($data['phone']))         $lines[] = "Телефон:                 {$data['phone']}";
    if (!empty($data['object_type']))   $lines[] = "Тип объекта:             {$data['object_type']}";
    if (!empty($data['work_type']))     $lines[] = "Формат работ:            {$data['work_type']}";
    if (!empty($data['area']))          $lines[] = "Площадь:                 {$data['area']} м²";
    if (!empty($data['rooms']))         $lines[] = "Комнат:                  {$data['rooms']}";
    if (!empty($data['district']))      $lines[] = "Район / ЖК:              {$data['district']}";
    if (!empty($data['planned_start'])) $lines[] = "Когда планируют начать:  {$data['planned_start']}";

    // Полный текст заявки из lead_summary (собирается на фронте)
    if (!empty($data['lead_summary'])) {
        $lines[] = '';
        $lines[] = str_repeat('-', 40);
        $lines[] = 'Полный текст заявки (lead_summary):';
        $lines[] = $data['lead_summary'];
    }

    return implode("\n", $lines);
}

/**
 * Отправляет письмо через PHP mail().
 * Тема кодируется в base64/UTF-8 (RFC 2047), тело передаётся как plain UTF-8 text.
 */
function sendEmailMessage(
    string $to,
    string $from,
    string $replyTo,
    string $subject,
    string $body,
    string $logFile
): bool {
    if ($to === '') {
        logError($logFile, 'Email не отправлен: пустой адрес получателя.');
        return false;
    }

    // Тема в UTF-8 base64 (стандарт RFC 2047 для заголовков)
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers   = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'From: Студия Симаковой <' . $from . '>';
    $headers[] = 'Reply-To: ' . $replyTo;
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    $ok = @mail($to, $encodedSubject, $body, implode("\r\n", $headers));

    if (!$ok) {
        logError($logFile, 'Ошибка mail(): письмо не отправлено.');
        return false;
    }

    return true;
}

// =============================================================================
// ПРИЁМ И ОБРАБОТКА ДАННЫХ
// =============================================================================

// Honeypot: если поле website заполнено — бот, тихо возвращаем success
$honeypot = cleanInput($_POST['website'] ?? '');
if ($honeypot !== '') {
    logError($logFile, 'Honeypot triggered. IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '—'));
    jsonResponse([
        'status'  => 'success',
        'message' => 'Заявка отправлена. Мы свяжемся с вами в ближайшее время.'
    ]);
}

// Собираем payload — все поля форм
$payload = [
    'lead_source'   => cleanInput($_POST['lead_source']   ?? ''),
    'lead_summary'  => cleanInput($_POST['lead_summary']  ?? ''),
    'name'          => cleanInput($_POST['name']          ?? ''),
    'phone'         => normalizePhone(cleanInput($_POST['phone'] ?? '')),
    'object_type'   => cleanInput($_POST['object_type']   ?? ''),
    'work_type'     => cleanInput($_POST['work_type']     ?? ''),
    'area'          => cleanInput($_POST['area']          ?? ''),
    'rooms'         => cleanInput($_POST['rooms']         ?? ''),   // было потеряно
    'district'      => cleanInput($_POST['district']      ?? ''),
    'planned_start' => cleanInput($_POST['planned_start'] ?? ''),
];

// Валидация обязательных полей
if ($payload['name'] === '') {
    jsonResponse(['status' => 'error', 'message' => 'Укажите имя.'], 422);
}

if ($payload['phone'] === '') {
    jsonResponse(['status' => 'error', 'message' => 'Укажите телефон.'], 422);
}

$digitsOnly = preg_replace('/\D+/', '', $payload['phone']) ?? '';
if (mb_strlen($digitsOnly) < 10) {
    jsonResponse(['status' => 'error', 'message' => 'Телефон выглядит некорректно.'], 422);
}

// =============================================================================
// ОТПРАВКА
// =============================================================================

// 1. Email — основной канал
$emailSent = sendEmailMessage(
    $emailTo,
    $emailFrom,
    $emailReplyTo,
    $emailSubject,
    buildEmailBody($payload),
    $logFile
);

// 2. Telegram — дополнительный канал (не фатальный)
$telegramSent = sendTelegramMessage(
    $telegramBotToken,
    $telegramChatId,
    buildTelegramMessage($payload),
    $requestTimeout,
    $logFile,
    $telegramProxy
);

// =============================================================================
// ОТВЕТ ФРОНТУ
// =============================================================================

if ($emailSent && !$telegramSent) {
    // Частичный успех — логируем, но пользователь не пугается
    logError($logFile, 'Email отправлен, Telegram не отправлен.');
}

if ($emailSent || $telegramSent) {
    jsonResponse([
        'status'  => 'success',
        'message' => 'Заявка отправлена. Мы свяжемся с вами в ближайшее время.'
    ]);
}

// Если не ушло ни туда, ни туда — ошибка
logError($logFile, "Заявка не отправлена. Email={$emailTo}, name={$payload['name']}, phone={$payload['phone']}");
jsonResponse([
    'status'  => 'error',
    'message' => 'Не удалось отправить заявку. Попробуйте ещё раз или напишите нам напрямую.'
], 500);
