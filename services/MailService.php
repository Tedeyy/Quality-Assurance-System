<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

class MailService {
    private static function configureMailer(): PHPMailer {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USER');
        $mail->Password   = getenv('SMTP_PASS');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = getenv('SMTP_PORT') ?: 587;
        $mail->setFrom(getenv('SMTP_FROM'), 'Quality Assurance Office');

        return $mail;
    }

    public static function sendContactInquiry($fromEmail, $subject, $message): bool {
        $adminEmail = getenv('ADMIN_EMAIL');
        if (empty($adminEmail)) {
            error_log('Contact inquiry could not be sent: ADMIN_EMAIL is not configured.');
            return false;
        }

        $mail = self::configureMailer();

        try {
            $safeFrom = htmlspecialchars($fromEmail, ENT_QUOTES, 'UTF-8');
            $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
            $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

            $mail->addAddress($adminEmail, 'Quality Assurance Administrator');
            $mail->addReplyTo($fromEmail);
            $mail->isHTML(true);
            $mail->Subject = '[QAO Inquiry] ' . $subject;
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 680px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden;'>
                    <div style='background: #001C57; color: #ffffff; padding: 20px 24px;'>
                        <h2 style='margin: 0; font-size: 20px;'>Quality Assurance Office Inquiry</h2>
                        <p style='margin: 6px 0 0; color: #dbeafe; font-size: 14px;'>Message submitted from the public landing page.</p>
                    </div>
                    <div style='padding: 24px;'>
                        <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>
                            <tr>
                                <td style='width: 130px; padding: 8px 0; color: #64748b; font-weight: 700;'>From</td>
                                <td style='padding: 8px 0; color: #0f172a;'>{$safeFrom}</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; color: #64748b; font-weight: 700;'>Recipient</td>
                                <td style='padding: 8px 0; color: #0f172a;'>{$adminEmail}</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; color: #64748b; font-weight: 700;'>Subject</td>
                                <td style='padding: 8px 0; color: #0f172a;'>{$safeSubject}</td>
                            </tr>
                        </table>
                        <div style='border-top: 1px solid #e2e8f0; padding-top: 20px; color: #1e293b; line-height: 1.7;'>
                            {$safeMessage}
                        </div>
                    </div>
                </div>
            ";
            $mail->AltBody = "From: {$fromEmail}\nRecipient: {$adminEmail}\nSubject: {$subject}\n\n{$message}";

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Contact inquiry could not be sent. Mailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }

    public static function sendAcknowledgment($toEmail, $userName, $activityTitle, $answers) {
        $mail = self::configureMailer();

        try {
            // Recipients
            $mail->addAddress($toEmail, $userName);

            // Attachments (Embedded)
            $mail->addEmbeddedImage(__DIR__ . '/../assets/img/formheader.png', 'formheader');

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Acknowledgment: Quality Service Feedback for ' . $activityTitle;
            
            // Build answer list
            $answerHtml = '<table style="width:100%; border-collapse: collapse; margin-top: 20px;">';
            foreach ($answers as $q => $a) {
                $answerHtml .= "<tr>
                    <td style='padding: 10px; border-bottom: 1px solid #e2e8f0; color: #64748b; width: 40%;'><strong>$q</strong></td>
                    <td style='padding: 10px; border-bottom: 1px solid #e2e8f0; color: #1e293b;'>$a</td>
                </tr>";
            }
            $answerHtml .= '</table>';

            $mail->Body = "
                <div style='font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 0; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;'>
                    <img src='cid:formheader' style='width: 100%; display: block;' alt='Quality Assurance Office'>
                    
                    <div style='padding: 30px;'>
                        <div style='text-align: center; margin-bottom: 30px;'>
                            <h2 style='color: #2563eb; margin: 0;'>Thank You for Your Feedback!</h2>
                            <p style='color: #64748b;'>We acknowledge your passion for quality service.</p>
                        </div>
                        
                        <p>Dear <strong>$userName</strong>,</p>
                        <p>Thank you for participating in the evaluation for <strong>$activityTitle</strong>. Your feedback is vital to our commitment to excellence.</p>
                        
                        <div style='background: #f8fafc; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                            <h3 style='margin-top: 0; color: #1e293b; font-size: 1rem;'>Your Responses Summary:</h3>
                            $answerHtml
                        </div>
                        
                        <p style='color: #64748b; font-size: 0.85rem; line-height: 1.5;'>
                            This email serves as an official acknowledgment of your submission. We appreciate the time and effort you took to help us improve our services.
                        </p>
                        
                        <div style='border-top: 1px solid #e2e8f0; margin-top: 30px; padding-top: 20px; text-align: center; color: #94a3b8; font-size: 0.75rem;'>
                            &copy; " . date('Y') . " Quality Assurance Office. All rights reserved.
                        </div>
                    </div>
                </div>
            ";

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }
}
