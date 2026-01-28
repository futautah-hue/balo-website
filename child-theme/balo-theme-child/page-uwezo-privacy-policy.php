<?php
/**
 * Template Name: Uwezo Privacy Policy
 * Description: Privacy policy page for Uwezo app with calm, transparent glass card layout.
 */
defined('ABSPATH') || exit;
get_header();
?>

<header class="privacy-hero" aria-labelledby="privacy-title">
  <a href="<?php echo esc_url(home_url('/')); ?>" class="site-logo" aria-label="Uwezo">
    <div class="aurora-logo">
      <img
        src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/balo-logo.jpg' ); ?>"
        alt="Uwezo"
        width="160" height="160"
        fetchpriority="high" decoding="async"
        class="balo-logo"
      >
    </div>
  </a>

  <div class="shimmer" aria-hidden="true"></div>
  <h1 id="privacy-title" class="privacy-title">Uwezo Privacy Policy</h1>
  <p class="privacy-sub">
    Your privacy matters to us. This policy explains how Uwezo collects, uses, and protects your data.
  </p>
</header>

<main class="privacy-main" role="main">
  <article class="privacy-card">
    <?php the_content(); // keep editor content flexible if used ?>

    <section class="pv-section">
      <h2>About Uwezo</h2>
      <p><strong>Uwezo</strong> is a platform designed to empower users with skills, knowledge, and opportunities. We are committed to protecting your privacy and ensuring your data is handled responsibly.</p>
    </section>

    <section class="pv-section">
      <h2>Information We Collect</h2>
      <p>Uwezo may collect the following types of information:</p>
      <ul class="pv-list">
        <li><strong>Account Information:</strong> Name, email address, and profile details you provide during registration.</li>
        <li><strong>Usage Data:</strong> Information about how you interact with the app, such as features accessed and time spent.</li>
        <li><strong>Device Information:</strong> Device type, operating system, and unique device identifiers.</li>
        <li><strong>Location Data:</strong> Approximate location based on IP address (if permitted).</li>
      </ul>
    </section>

    <section class="pv-section">
      <h2>How We Use Your Data</h2>
      <p>We use collected data to:</p>
      <ul class="pv-list">
        <li>Provide and improve Uwezo's features and functionality.</li>
        <li>Personalize your experience and suggest relevant content.</li>
        <li>Communicate important updates, notifications, and promotional content (with your consent).</li>
        <li>Analyze usage patterns to enhance performance and user experience.</li>
        <li>Ensure security and prevent fraudulent activity.</li>
      </ul>
    </section>

    <section class="pv-section">
      <h2>Data Sharing</h2>
      <p>Uwezo does not sell your personal information. We may share data only in the following circumstances:</p>
      <ul class="pv-list">
        <li><strong>Service Providers:</strong> Third-party services that help us operate the app (e.g., hosting, analytics).</li>
        <li><strong>Legal Requirements:</strong> When required by law or to protect our rights and users.</li>
        <li><strong>Business Transfers:</strong> In the event of a merger, acquisition, or sale of assets.</li>
      </ul>
    </section>

    <section class="pv-section">
      <h2>Cookies &amp; Tracking Technologies</h2>
      <p>Uwezo uses cookies and similar technologies to enhance your experience. These help us remember your preferences, analyze traffic, and provide personalized content. You can manage cookie preferences in your device settings.</p>
    </section>

    <section class="pv-section">
      <h2>Data Security</h2>
      <p>We implement industry-standard security measures to protect your data, including encryption, secure servers, and access controls. However, no method of transmission over the internet is 100% secure.</p>
    </section>

    <section class="pv-section">
      <h2>AI &amp; Machine Learning</h2>
      <p>Uwezo may use anonymized, aggregated data to improve AI-driven features such as recommendations and content suggestions. We do <strong>not</strong> use personally identifiable information for AI training without your explicit consent.</p>
    </section>

    <section class="pv-section">
      <h2>Your Rights &amp; Choices</h2>
      <p>You have the right to:</p>
      <ul class="pv-list">
        <li>Access and review the personal data we hold about you.</li>
        <li>Request corrections to inaccurate or incomplete data.</li>
        <li>Request deletion of your data (subject to legal obligations).</li>
        <li>Opt out of promotional communications at any time.</li>
        <li>Withdraw consent for data processing where applicable.</li>
      </ul>
      <p>To exercise these rights, contact us at
        <a href="mailto:baloservices@proton.me">baloservices@proton.me</a>.
      </p>
      <p class="pv-note">
        We aim to respond within <strong>2–3 business days</strong>. Please note we are closed on weekends.
      </p>
    </section>

    <section class="pv-section">
      <h2>Children's Privacy</h2>
      <p>Uwezo is not intended for users under the age of 13. We do not knowingly collect personal information from children. If we become aware of such collection, we will take steps to delete the information.</p>
    </section>

    <section class="pv-section">
      <h2>Changes to This Policy</h2>
      <p>We may update this privacy policy from time to time. Changes will be posted on this page with an updated effective date. We encourage you to review this policy periodically.</p>
    </section>

    <section class="pv-section">
      <h2>Contact Us</h2>
      <p>
        If you have questions, concerns, or requests regarding this privacy policy or your personal data, please contact us at:
      </p>
      <p>
        <strong>Email:</strong> <a href="mailto:baloservices@proton.me">baloservices@proton.me</a>
      </p>
      <p class="pv-note">
        This is our official email for privacy and data-related inquiries.
      </p>
    </section>

    <section class="pv-section">
      <h2>Summary</h2>
      <ul class="pv-list">
        <li>We collect minimal data necessary to provide Uwezo's services.</li>
        <li>Your data is stored securely and never sold to third parties.</li>
        <li>Cookies enhance functionality and can be managed in your settings.</li>
        <li>Anonymized data may improve AI features, but never includes personal details.</li>
        <li>You can access, correct, or delete your data at any time.</li>
        <li>Contact us at <a href="mailto:baloservices@proton.me">baloservices@proton.me</a> for any privacy concerns.</li>
      </ul>
      <p class="pv-note">Thank you for trusting Uwezo. We're committed to protecting your privacy every step of the way.</p>
    </section>
  </article>
</main>

<?php get_footer(); ?>
