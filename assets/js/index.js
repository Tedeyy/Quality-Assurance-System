document.addEventListener('DOMContentLoaded', () => {
    // Smooth scrolling for navigation links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const targetId = this.getAttribute('href').slice(1);
            const targetElement = document.getElementById(targetId);
            
            if (targetElement) {
                window.scrollTo({
                    top: targetElement.offsetTop - 80, // Adjust for fixed header
                    behavior: 'smooth'
                });
            }
        });
    });

    // Reveal animations on scroll
    const observerOptions = {
        threshold: 0.1
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('reveal-active');
            }
        });
    }, observerOptions);

    document.querySelectorAll('.about-card, .feature-card, .news-card').forEach(el => {
        el.classList.add('reveal');
        observer.observe(el);
    });

    // News Slider Logic
    let currentSlide = 0;
    let slideInterval = null;
    const track = document.getElementById('newsTrack');
    const slides = document.querySelectorAll('.news-slide');
    const dots = document.querySelectorAll('.news-dot');
    const totalSlides = slides.length;

    if (totalSlides > 0) {
        window.goToSlide = function(index) {
            currentSlide = index;
            const offset = -currentSlide * 100;
            track.style.transform = `translateX(${offset}%)`;
            
            // Update dots
            dots.forEach((dot, i) => {
                dot.style.background = i === currentSlide ? 'var(--accent-blue)' : '#cbd5e1';
                dot.style.transform = i === currentSlide ? 'scale(1.3)' : 'scale(1)';
            });
            
            // Reset timer on manual move
            resetTimer();
        };

        function resetTimer() {
            if (slideInterval) clearInterval(slideInterval);
            slideInterval = setInterval(() => {
                currentSlide = (currentSlide + 1) % totalSlides;
                // Move without triggering another reset
                const offset = -currentSlide * 100;
                track.style.transform = `translateX(${offset}%)`;
                dots.forEach((dot, i) => {
                    dot.style.background = i === currentSlide ? 'var(--accent-blue)' : '#cbd5e1';
                    dot.style.transform = i === currentSlide ? 'scale(1.3)' : 'scale(1)';
                });
            }, 10000);
        }

        // Initialize
        resetTimer();
        
        // Initial state for dots
        dots.forEach((dot, i) => {
            dot.style.background = i === currentSlide ? 'var(--accent-blue)' : '#cbd5e1';
            dot.style.transform = i === currentSlide ? 'scale(1.3)' : 'scale(1)';
        });
    }
});
