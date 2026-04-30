<?php
session_start();
require_once __DIR__ . '/config/database.php';
$db = (new Database())->getConnection();

// Fetch Latest News
$stmt = $db->query("SELECT * FROM news ORDER BY created_at DESC LIMIT 6");
$news_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Latest News
$stmt = $db->query("SELECT * FROM news ORDER BY created_at DESC LIMIT 6");
$news_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description"
        content="Your one-stop solution for all quality assurance needs. Elevate your quality standards.">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/index.css?v=1.0.1">
    <link rel="icon" type="image/png" href="assets/img/QAO_logo.png">
    <title>Quality Assurance Office | NBSC</title>
</head>

<body>
    <div class="background-accents">
        <div class="accent-strand strand-1"></div>
        <div class="accent-strand strand-2"></div>
    </div>

    <nav class="navbar">
        <div class="logo" style="display: flex; align-items: center; gap: 10px;">
            <img src="./assets/img/NBSC_logo.png" alt="NBSC Logo" style="height: 35px; width: auto;">
            <img src="./assets/img/QAO_logo.png" alt="QAO Logo" style="height: 35px; width: auto;">
            <span style="font-weight: 700; color: var(--accent-blue); margin-left: 5px;">Quality Assurance Office</span>
        </div>
        <div class="nav-links">
            <a href="#home">Home</a>
            <a href="#about">About</a>
            <a href="#features">Features</a>
        </div>
        <a href="./views/feed.php" class="btn btn-primary">Sign In</a>
    </nav>

    <main class="hero" id="home">
        <div class="hero-content">
            <h1 class="hero-title">Elevate Your <span>Quality Standards</span></h1>
            <p class="hero-subtitle">Streamline your workflows, automate testing, and deliver flawless products with our
                state-of-the-art Quality Assurance System.</p>
            <div class="hero-actions">
                <a href="#get-started" class="btn btn-primary btn-large">Get Started Now</a>
                <a href="#demo" class="btn btn-secondary btn-large">Watch Demo</a>
            </div>
        </div>
    </main>

    <section class="about" id="about">
        <div class="container">
            <div class="section-header" style="text-align: center; margin-bottom: 3rem;">
                <h2 style="font-size: 2.5rem; margin-bottom: 1rem;">About Our Quality Commitment</h2>
                <p style="color: var(--text-secondary); max-width: 600px; margin: 0 auto;">We are dedicated to fostering
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
    <section class="news" id="news" style="padding: 6rem 5%; background: white;">
        <div class="container" style="max-width: 1200px; margin: 0 auto;">
            <div class="section-header" style="text-align: center; margin-bottom: 3rem;">
                <h2 style="font-size: 2.5rem; margin-bottom: 1rem;">Latest Updates</h2>
                <p style="color: var(--text-secondary); max-width: 600px; margin: 0 auto;">Stay informed with the latest announcements and happenings from the Quality Assurance Office.</p>
            </div>
            
            <div class="news-slider" style="position: relative; overflow: hidden; border-radius: 20px;">
                <div class="news-track" id="newsTrack" style="display: flex; transition: transform 0.8s cubic-bezier(0.4, 0, 0.2, 1);">
                    <?php if (empty($news_items)): ?>
                        <div style="flex: 0 0 100%; text-align: center; padding: 5rem 3rem; background: #f8fafc; border-radius: 20px; color: var(--text-secondary);">
                            <p>No news updates at the moment. Stay tuned!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($news_items as $news): ?>
                            <div class="news-slide" style="flex: 0 0 100%; padding: 0 10px;">
                                <div class="news-card" style="display: flex; background: white; border-radius: 20px; overflow: hidden; border: 1px solid var(--border-color); box-shadow: 0 4px 20px rgba(0,0,0,0.04); min-height: 400px; max-height: 500px;">
                                    <div class="news-image" style="flex: 1; background: #f1f5f9; position: relative; min-width: 50%;">
                                        <?php 
                                            $local_image = "assets/img/news/" . $news['news_id'] . ".jpg";
                                            if (file_exists($local_image)): 
                                        ?>
                                            <img src="<?= $local_image ?>" alt="News Image" style="width: 100%; height: 100%; object-fit: cover;">
                                        <?php else: ?>
                                            <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: #cbd5e1;">
                                                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="news-content" style="flex: 1; padding: 3rem; display: flex; flex-direction: column; justify-content: center;">
                                        <span style="font-size: 0.85rem; color: var(--accent-blue); font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">
                                            <?= date('F d, Y', strtotime($news['event_date'] ?: $news['created_at'])) ?>
                                        </span>
                                        <h3 style="margin: 1.5rem 0; font-size: 2rem; line-height: 1.2; color: var(--text-primary);"><?= htmlspecialchars($news['title']) ?></h3>
                                        <p style="font-size: 1.05rem; color: var(--text-secondary); line-height: 1.7; display: -webkit-box; -webkit-line-clamp: 5; -webkit-box-orient: vertical; overflow: hidden;">
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
                    <div class="news-nav" style="display: flex; justify-content: center; gap: 12px; margin-top: 2rem;">
                        <?php foreach ($news_items as $i => $news): ?>
                            <div class="news-dot" onclick="goToSlide(<?= $i ?>)" style="width: 10px; height: 10px; border-radius: 50%; background: #cbd5e1; cursor: pointer; transition: all 0.3s ease;"></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="features" id="features">
        <div class="feature-card">
            <div class="feature-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                </svg>
            </div>
            <h3>Automated Workflows</h3>
            <p>Accelerate your testing with intelligent automation and seamless integrations tailored for enterprise
                needs.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path>
                    <path d="M22 12A10 10 0 0 0 12 2v10z"></path>
                </svg>
            </div>
            <h3>Real-time Analytics</h3>
            <p>Gain actionable insights with our comprehensive dashboard, reporting tools, and precise metrics.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>
            <h3>Enterprise Security</h3>
            <p>Bank-grade security ensures your testing data, protocols, and intellectual property remain strictly
                confidential.</p>
        </div>
    </section>

    <footer>
        <div class="footer-content">
            <div class="footer-brand">
                <h3>QA System</h3>
                <p>Your one-stop solution for quality assurance.</p>
            </div>
            <div class="footer-links">
                <p>Follow us</p>
                <div class="socials">
                    <a href="https://www.facebook.com/">Facebook</a>
                    <a href="https://www.twitter.com/">Twitter</a>
                    <a href="https://www.linkedin.com/">LinkedIn</a>
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