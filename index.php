<?php
session_start();
require_once __DIR__ . '/config/database.php';
$db = (new Database())->getConnection();

// Fetch Latest News
$stmt = $db->query("SELECT * FROM news ORDER BY created_at DESC LIMIT 6");
$news_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$admin_email = getenv('ADMIN_EMAIL') ?: ($_ENV['ADMIN_EMAIL'] ?? '');
$contact_status = $_GET['contact'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description"
        content="Northern Bukidnon State College Quality Assurance Office portal for accreditation, activity evaluation, documents, and stakeholder feedback.">
    <meta name="theme-color" content="#001c57">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="QA System">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="manifest.webmanifest">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/index.css?v=1.0.1">
    <link rel="icon" type="image/png" href="assets/img/QAO_logo.png">
    <link rel="apple-touch-icon" href="assets/img/pwa-icon-192.png">
    <title>Quality Assurance Office | NBSC</title>
</head>

<body>
    <div class="background-accents">
        <div class="accent-strand strand-1"></div>
        <div class="accent-strand strand-2"></div>
    </div>
    <script src="assets/js/pwa.js" defer></script>

    <nav class="navbar">
        <a href="./index.php" class="qa-logo-container">
            <img src="./assets/img/NBSC_logo.png" alt="NBSC Logo">
            <img src="./assets/img/QAO_logo.png" alt="QAO Logo">
            <div class="qa-logo-text">
                <span>QA System</span>
                <span class="qa-logo-sub">Quality Assurance</span>
            </div>
        </a>
        <div class="nav-links">
            <a href="#home">Home</a>
            <a href="#about">About</a>
            <a href="#news">Updates</a>
            <a href="#features">Features</a>
            <a href="#contact">Contact</a>
        </div>
        <a href="./views/feed.php" class="btn btn-primary">Open Portal</a>
    </nav>

    <main class="hero" id="home">
        <div class="hero-content">
            <p class="hero-kicker">Northern Bukidnon State College</p>
            <h1 class="hero-title">Quality assurance work, organized in one place.</h1>
            <p class="hero-subtitle">Track accreditation requirements, monitor activities, manage documents, and turn
                stakeholder feedback into evidence for continuous institutional improvement.</p>
            <div class="hero-actions">
                <a href="./views/feed.php" class="btn btn-primary btn-large">Open QA Portal</a>
                <a href="#news" class="btn btn-secondary btn-large">View Updates</a>
            </div>
        </div>
        <div class="hero-media" aria-label="NBSC campus and quality assurance highlights">
            <img src="./assets/img/nbsc_campus.jpg" alt="Northern Bukidnon State College campus">
            <div class="hero-panel">
                <span>Institutional Quality Cycle</span>
                <ul>
                    <li>Prepare evidence</li>
                    <li>Monitor compliance</li>
                    <li>Review feedback</li>
                </ul>
            </div>
        </div>
    </main>

    <section class="about" id="about">
        <div class="container">
            <div class="section-header">
                <span class="section-kicker">About QAO</span>
                <h2>Quality commitment with measurable follow-through</h2>
                <p>We are dedicated to fostering
                    a culture of excellence and continuous improvement through rigorous quality standards.</p>
            </div>
            <div class="about-grid">
                <div class="about-card mission">
                    <div class="card-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10" />
                            <circle cx="12" cy="12" r="6" />
                            <circle cx="12" cy="12" r="2" />
                        </svg>
                    </div>
                    <h2>Our Mission</h2>
                    <p>Northern Bukidnon State College advances excellence in teaching, innovative research, and
                        impactful community service through strategic partnerships, and inclusive and equitable
                        education, empowering transformative development in Bukidnon and Region X.</p>
                </div>
                <div class="about-card vision">
                    <div class="card-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                    </div>
                    <h2>Our Vision</h2>
                    <p>A recognized institution for inclusive, culturally responsive, and sustainable higher education.
                    </p>
                </div>
                <div class="about-card objectives">
                    <div class="card-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 20h9" />
                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                        </svg>
                    </div>
                    <h2>Our Objectives</h2>
                    <p>The QAO is committed to establishing excellence through comprehensive quality management,
                        implementing frameworks, conducting program reviews, and establishing stakeholder feedback
                        mechanisms to ensure academic programs meet industry standards.</p>
                </div>
                <div class="about-card services">
                    <div class="card-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m16 6 4 14" />
                            <path d="M12 6v14" />
                            <path d="m8 6-4 14" />
                            <path d="M2 20h20" />
                            <path d="M2 2l20 20" />
                        </svg>
                    </div>
                    <h2>Our Services</h2>
                    <p>Providing monitoring, evaluation, and feedback management services. We facilitate Activity
                        Monitoring and Evaluation (AME) and manage client feedback through Satisfaction Surveys (CSS)
                        and CRM processes for institutional improvement.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- News Section -->
    <section class="news" id="news">
        <div class="container">
            <div class="section-header">
                <span class="section-kicker">Announcements</span>
                <h2>Latest Updates</h2>
                <p>Stay informed with the latest announcements and happenings from the Quality Assurance Office.</p>
            </div>
            
            <div class="news-slider">
                <div class="news-track" id="newsTrack">
                    <?php if (empty($news_items)): ?>
                        <div class="empty-news">
                            <p>No news updates at the moment. Stay tuned!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($news_items as $news): ?>
                            <div class="news-slide">
                                <div class="news-card">
                                    <div class="news-image">
                                        <?php 
                                            $local_image = "assets/img/news/" . $news['news_id'] . ".jpg";
                                            if (file_exists($local_image)): 
                                        ?>
                                            <img src="<?= $local_image ?>" alt="<?= htmlspecialchars($news['title']) ?>">
                                        <?php else: ?>
                                            <div class="news-placeholder">
                                                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="news-content">
                                        <span class="news-date">
                                            <?= date('F d, Y', strtotime($news['event_date'] ?: $news['created_at'])) ?>
                                        </span>
                                        <h3><?= htmlspecialchars($news['title']) ?></h3>
                                        <p>
                                            <?= htmlspecialchars($news['content']) ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($news_items)): ?>
                    <!-- Navigation Dots -->
                    <div class="news-nav">
                        <?php foreach ($news_items as $i => $news): ?>
                            <button class="news-dot" onclick="goToSlide(<?= $i ?>)" aria-label="Show update <?= $i + 1 ?>"></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="features" id="features">
        <div class="section-header feature-header">
            <span class="section-kicker">Portal Features</span>
            <h2>Built for recurring quality assurance work</h2>
            <p>Focused tools for the daily evidence, reporting, and review tasks that keep programs audit-ready.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                </svg>
            </div>
            <h3>Accreditation Tracking</h3>
            <p>Organize requirements, map evidence, and monitor progress across programs with clearer accountability.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path>
                    <path d="M22 12A10 10 0 0 0 12 2v10z"></path>
                </svg>
            </div>
            <h3>Activity Evaluation</h3>
            <p>Support AME workflows with structured forms, summaries, and reporting for institutional activities.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>
            <h3>Document Control</h3>
            <p>Keep QA documents easier to find, review, and align with current accreditation and compliance needs.</p>
        </div>
    </section>

    <section class="contact" id="contact">
        <div class="contact-shell">
            <div class="contact-copy">
                <span class="section-kicker">Contact Us</span>
                <h2>Send a message to the Quality Assurance Office</h2>
                <p>For accreditation, document, activity evaluation, or general quality assurance inquiries, send a message directly to the administrator account configured for this system.</p>
                <div class="contact-note">
                    <strong>Recipient</strong>
                    <span><?= htmlspecialchars($admin_email ?: 'ADMIN_EMAIL is not configured') ?></span>
                </div>
            </div>
            <form class="email-form" action="api/contact.php" method="POST">
                <?php if ($contact_status): ?>
                    <?php
                        $contact_messages = [
                            'sent' => ['type' => 'success', 'text' => 'Your message has been sent successfully.'],
                            'missing' => ['type' => 'error', 'text' => 'Please complete all message fields.'],
                            'invalid_email' => ['type' => 'error', 'text' => 'Please enter a valid inquiree email address.'],
                            'too_long' => ['type' => 'error', 'text' => 'Your subject or message is too long.'],
                            'config_error' => ['type' => 'error', 'text' => 'The administrator email is not configured correctly.'],
                            'failed' => ['type' => 'error', 'text' => 'Your message could not be sent. Please try again later.'],
                        ];
                        $contact_message = $contact_messages[$contact_status] ?? null;
                    ?>
                    <?php if ($contact_message): ?>
                        <div class="contact-alert <?= $contact_message['type'] ?>">
                            <?= htmlspecialchars($contact_message['text']) ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="email-header">
                    <label>
                        <span>Subject</span>
                        <input type="text" name="subject" maxlength="160" placeholder="e.g. Accreditation document inquiry" required>
                    </label>
                    <label>
                        <span>Recipient</span>
                        <input type="email" value="<?= htmlspecialchars($admin_email) ?>" readonly>
                    </label>
                    <label>
                        <span>Inquiree Email</span>
                        <input type="email" name="inquiree_email" placeholder="your.email@example.com" required>
                    </label>
                </div>
                <label class="email-body">
                    <span>Message Body</span>
                    <textarea name="message" rows="9" maxlength="5000" placeholder="Write your inquiry here..." required></textarea>
                </label>
                <div class="email-actions">
                    <p>The message will be delivered through the configured SMTP account.</p>
                    <button type="submit" class="btn btn-primary">Send Message</button>
                </div>
            </form>
        </div>
    </section>

    <footer>
        <div class="footer-content">
            <div class="footer-brand">
                <h3>NBSC Quality Assurance Office</h3>
                <p>Supporting excellence through evidence-based improvement.</p>
            </div>
            <div class="footer-links">
                <p>Follow us</p>
                <div class="socials">
                    <a href="https://www.facebook.com/">Facebook</a>
                    <a href="https://www.twitter.com/">Twitter</a>
                    <a href="https://www.linkedin.com/">LinkedIn</a>
                    <a href="user_manual.html" target="_blank">User Manual</a>
                    <a href="terms.php">Terms & Policy</a>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; 2026 Quality Assurance System. All rights reserved.</p>
        </div>
    </footer>
    <script type="text/javascript" src="assets/js/index.js?v=1.0.1"></script>
</body>

</html>
