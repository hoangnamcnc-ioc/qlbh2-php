<?php
require_once __DIR__ . '/config.php';

/**
 * Gui email qua SMTP co xac thuc.
 *
 * Vi sao khong dung ham mail() cua PHP: hosting nay KHONG co mail server noi bo (ket noi
 * localhost:25 bi tu choi), nen mail() luon tra ve false - moi email truoc day (dat lai mat khau,
 * nhac het han, bao cao tuan...) deu khong he duoc gui di. Ngoai ra ten mien kt-soft.vn chua co
 * SPF/DKIM nen mail tu dia chi @kt-soft.vn cung de bi Gmail chan.
 *
 * Luu y ve cong: cong 587 (STARTTLS) bi chan tu may chu nay (mo duoc TCP nhung khong nhan duoc
 * greeting), chi cong 465 (SSL truc tiep) hoat dong - da kiem chung bang bat tay SMTP that.
 *
 * Tra ve true neu may chu SMTP da nhan thu. Khong bao gio nem exception ra ngoai: email hong
 * khong duoc lam vo luong nghiep vu dang chay (vd dang nhap, thanh toan).
 */
/**
 * Gui email KHONG chan trang web: luu vao hang doi (bang mail_queue) roi gui NGAY SAU KHI da tra trang ve cho nguoi dung;
 * gui loi (SMTP chap chon, het thoi gian cho) thi tu thu lai sau 10/20/30... phut qua cron (mailQueueFlush). Tra ve true neu da
 * nhan vao hang doi. Neu chua co bang hang doi thi gui truc tiep nhu truoc.
 */
function sendMail(string $to, string $subject, string $body, ?string $replyTo = null): bool
{
    if (!defined('SMTP_HOST') || SMTP_USER === '' || SMTP_PASS === '') {
        error_log("sendMail: chua cau hinh SMTP, bo qua email gui toi $to (tieu de: $subject)");
        return false;
    }
    try {
        $pdo = db();
        $pdo->prepare('INSERT INTO mail_queue (to_email, subject, body, reply_to, next_try) VALUES (?,?,?,?,NOW())')
            ->execute([$to, $subject, $body, $replyTo]);
        $id = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        return sendMailDirect($to, $subject, $body, $replyTo);
    }
    register_shutdown_function(static function () use ($id): void {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        mailQueueSendOne($id);
    });
    return true;
}

/** Gui 1 thu trong hang doi (neu con cho gui), cap nhat trang thai / so lan thu. */
function mailQueueSendOne(int $id): void
{
    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT * FROM mail_queue WHERE id = ? AND status = 'PENDING' AND next_try <= NOW()");
        $st->execute([$id]);
        $m = $st->fetch();
        if (!$m) return;
        // danh dau tam de 2 tien trinh khong cung gui 1 thu
        $claim = $pdo->prepare("UPDATE mail_queue SET next_try = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ? AND next_try <= NOW()");
        $claim->execute([$id]);
        if ($claim->rowCount() === 0) return;
        $ok = sendMailDirect($m['to_email'], $m['subject'], $m['body'], $m['reply_to']);
        if ($ok) {
            $pdo->prepare("UPDATE mail_queue SET status = 'SENT', attempts = attempts + 1, sent_at = NOW() WHERE id = ?")->execute([$id]);
        } else {
            $attempts = (int) $m['attempts'] + 1;
            $pdo->prepare("UPDATE mail_queue SET attempts = ?, status = ?, next_try = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?")
                ->execute([$attempts, $attempts >= 6 ? 'FAILED' : 'PENDING', 10 * $attempts, $id]);
        }
    } catch (Throwable $e) {
        error_log('mailQueueSendOne: ' . $e->getMessage());
    }
}

/** Cron: gui lai cac thu dang cho (toi da $limit thu moi lan), don thu da gui/that bai cu hon 14 ngay. */
function mailQueueFlush(int $limit = 10): int
{
    try {
        $pdo = db();
        $ids = $pdo->query("SELECT id FROM mail_queue WHERE status = 'PENDING' AND next_try <= NOW() ORDER BY id LIMIT " . (int) $limit)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            mailQueueSendOne((int) $id);
        }
        $pdo->exec("DELETE FROM mail_queue WHERE status IN ('SENT','FAILED') AND created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)");
        return count($ids);
    } catch (Throwable $e) {
        return 0;
    }
}

function sendMailDirect(string $to, string $subject, string $body, ?string $replyTo = null): bool
{
    if (!defined('SMTP_HOST') || SMTP_USER === '' || SMTP_PASS === '') {
        error_log("sendMail: chua cau hinh SMTP, bo qua email gui toi $to (tieu de: $subject)");
        return false;
    }

    $fromEmail = SMTP_FROM !== '' ? SMTP_FROM : SMTP_USER;
    $eol = "\r\n";

    // Tieu de co dau tieng Viet phai ma hoa MIME encoded-word, neu khong se hien thi loi font.
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFromName = '=?UTF-8?B?' . base64_encode(SMTP_FROM_NAME) . '?=';

    $headers = [
        'Date: ' . date('r'),
        'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
        'To: <' . $to . '>',
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        // Base64 tranh han che do dai dong cua SMTP va tranh phai "dot-stuffing" thu cong
        // (dong chi co dau cham se bi hieu la ket thuc thu).
        'Content-Transfer-Encoding: base64',
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (SMTP_MESSAGE_ID_DOMAIN ?: 'kt-soft.vn') . '>',
    ];
    if ($replyTo !== null && $replyTo !== '') {
        $headers[] = 'Reply-To: <' . $replyTo . '>';
    }

    $message = implode($eol, $headers) . $eol . $eol . chunk_split(base64_encode($body), 76, $eol);

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(
        'ssl://' . SMTP_HOST . ':' . SMTP_PORT,
        $errno,
        $errstr,
        SMTP_TIMEOUT
    );
    if (!$fp) {
        error_log("sendMail: khong ket noi duoc SMTP [$errno] $errstr");
        return false;
    }
    stream_set_timeout($fp, SMTP_TIMEOUT);

    $ok = true;
    $expect = function (string $command, array $expectedCodes) use ($fp, &$ok, $eol): bool {
        if (!$ok) {
            return false;
        }
        if ($command !== '') {
            fwrite($fp, $command . $eol);
        }
        $response = '';
        while (($line = fgets($fp, 515)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        $code = substr(trim($response), 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            // Khong ghi $command vao log khi do la mat khau da ma hoa base64.
            error_log('sendMail: SMTP tra ve khong mong doi: ' . trim($response));
            $ok = false;
            return false;
        }
        return true;
    };

    $expect('', ['220']);
    $expect('EHLO ' . (SMTP_MESSAGE_ID_DOMAIN ?: 'kt-soft.vn'), ['250']);
    $expect('AUTH LOGIN', ['334']);
    $expect(base64_encode(SMTP_USER), ['334']);
    $expect(base64_encode(SMTP_PASS), ['235']);
    $expect('MAIL FROM:<' . $fromEmail . '>', ['250']);
    $expect('RCPT TO:<' . $to . '>', ['250', '251']);
    $expect('DATA', ['354']);
    $expect($message . $eol . '.', ['250']);

    @fwrite($fp, 'QUIT' . $eol);
    @fclose($fp);

    if (!$ok) {
        error_log("sendMail: gui that bai toi $to (tieu de: $subject)");
    }
    return $ok;
}
