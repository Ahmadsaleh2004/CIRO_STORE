<?php
/**
 * app/views/page/contact.php
 * البيانات جاهزة من ContactController::contact()
 */
$showLoginAlert = !$userLoggedIn;
?>

<main id="main-content" role="main">
<section class="container py-5">

    <nav class="store-breadcrumb mb-4">
        <a href="<?= URLROOT ?>">🏠 Home</a>
        <span class="sep">/</span>
        <span class="current">Contact Us</span>
    </nav>

    <div class="text-center mb-5">
        <h1 class="fw-bold">Contact Us</h1>
        <p class="lead">We Would Love To Hear From You</p>
    </div>

    <div class="row g-4">
        <!-- Contact Info -->
        <div class="col-lg-5">
            <div class="card p-4 h-100">
                <h2 class="h3 mb-4">📬 Contact Information</h2>
                <p>📍 Cairo, Egypt</p>
                <p>📞 <?= htmlspecialchars($phone) ?></p>
                <p>✉️ <?= htmlspecialchars($email) ?></p>
                <p class="mb-0">🕒 <?= htmlspecialchars($workingHours) ?></p>
            </div>
        </div>

        <!-- Contact Form -->
        <div class="col-lg-7">
            <div class="card p-4">
                <h2 class="h3 mb-4">💌 Send Message</h2>

                <?php if ($showLoginAlert): ?>
                    <div class="alert alert-warning mb-4">
                        <strong>🔒 Please <a href="#" data-bs-toggle="modal" data-bs-target="#loginModal">log in</a> to send us a message.</strong>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= URLROOT ?>/contact" novalidate>
                    <input type="hidden" name="send_message" value="1">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                    <div class="float-group">
                        <input type="text" id="contactName" name="full_name" placeholder=" " required
                               value="<?= htmlspecialchars($prefillName) ?>"
                               autocomplete="name"
                               <?= $userLoggedIn ? 'readonly' : '' ?>>
                        <label>Full Name</label>
                        <?php if ($userLoggedIn): ?>
                            <small class="text-muted d-block mt-1">Linked to your account</small>
                        <?php endif; ?>
                    </div>
                    <div class="float-group">
                        <input type="email" id="contactEmail" name="email" placeholder=" " required
                               value="<?= htmlspecialchars($prefillEmail) ?>"
                               autocomplete="email"
                               <?= $userLoggedIn ? 'readonly' : '' ?>>
                        <label>Email Address</label>
                        <?php if ($userLoggedIn): ?>
                            <small class="text-muted d-block mt-1">Linked to your account</small>
                        <?php endif; ?>
                    </div>
                    <div class="float-group">
                        <textarea id="contactMessage" name="message" rows="5" placeholder=" " required
                            <?= !$userLoggedIn ? 'disabled' : '' ?>></textarea>
                        <label>Your Message</label>
                    </div>
                    <?php if ($userLoggedIn): ?>
                    <button id="contactSendBtn" type="submit" class="btn btn-success w-100 btn-disabled-faded"
                            disabled aria-disabled="true">Send Message</button>
                    <?php else: ?>
                    <button id="contactSendBtn" type="button" class="btn btn-success w-100 btn-disabled-faded"
                            disabled aria-disabled="true"
                            data-bs-toggle="modal" data-bs-target="#loginModal">
                        Send Message (Login Required)
                    </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

</section>
</main>

<?= pageData(['__userLoggedIn' => (bool) $userLoggedIn]) ?>
<script src="<?= URLROOT ?>/js/features/contact.js" defer></script>