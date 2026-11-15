// Mobile navigation: opens and closes the menu panel on small screens.
const navbarToggle = document.querySelector('.navbar-toggle');
const navbarMenu = document.getElementById('navbar-menu');

function setMenuOpen(isOpen) {
    navbarToggle.setAttribute('aria-expanded', String(isOpen));
    navbarMenu.classList.toggle('is-open', isOpen);
}

navbarToggle.addEventListener('click', () => {
    const isOpen = navbarToggle.getAttribute('aria-expanded') === 'true';
    setMenuOpen(!isOpen);
});

// Choosing a link closes the menu.
navbarMenu.addEventListener('click', (event) => {
    if (event.target.closest('a')) {
        setMenuOpen(false);
    }
});

// Escape closes the menu and returns focus to the button that opened it.
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && navbarMenu.classList.contains('is-open')) {
        setMenuOpen(false);
        navbarToggle.focus();
    }
});

// If the screen grows to desktop size while the menu is open, reset it.
window.matchMedia('(min-width: 901px)').addEventListener('change', (event) => {
    if (event.matches) {
        setMenuOpen(false);
    }
});


// Scroll reveal: marks each [data-reveal] element visible once it scrolls into view.
const revealElements = document.querySelectorAll('[data-reveal]');

if ('IntersectionObserver' in window) {
    const revealObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15 });

    revealElements.forEach((element) => revealObserver.observe(element));
} else {
    revealElements.forEach((element) => element.classList.add('is-visible'));
}


// Testimonial dots: on smaller screens the cards scroll sideways and the
// dots show (and control) which card is in view.
const testimonialTrack = document.querySelector('.testimonial-track');
const testimonialDots = document.querySelectorAll('.testimonial-dot');
const testimonialCards = testimonialTrack.querySelectorAll('.testimonial-card');

function setActiveDot(activeIndex) {
    testimonialDots.forEach((dot, index) => {
        const isActive = index === activeIndex;
        dot.classList.toggle('is-active', isActive);
        dot.setAttribute('aria-current', String(isActive));
    });
}

testimonialDots.forEach((dot, index) => {
    dot.addEventListener('click', () => {
        testimonialCards[index].scrollIntoView({ block: 'nearest', inline: 'start' });
        setActiveDot(index);
    });
});

testimonialTrack.addEventListener('scroll', () => {
    const maxScroll = testimonialTrack.scrollWidth - testimonialTrack.clientWidth;
    if (maxScroll <= 0) {
        return;
    }
    const progress = Math.abs(testimonialTrack.scrollLeft) / maxScroll;   // negative in right-to-left pages
    setActiveDot(Math.round(progress * (testimonialCards.length - 1)));
}, { passive: true });


// Welcome modal: opened by "Get Started" (shared modal code lives in modals.js).
const welcomeModal = document.getElementById('welcome-modal');

document.querySelector('.get-started-btn').addEventListener('click', () => openModal(welcomeModal));
