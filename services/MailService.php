<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

class MailService {
    public static function sendAcknowledgment($toEmail, $userName, $activityTitle, $answers) {
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = getenv('SMTP_USER');
            $mail->Password   = getenv('SMTP_PASS');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = getenv('SMTP_PORT') ?: 587;

            // Recipients
            $mail->setFrom(getenv('SMTP_FROM'), 'Quality Assurance Office');
            $mail->addAddress($toEmail, $userName);

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
                <div style='font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
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
            ";

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }
}
