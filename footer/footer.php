<style>
    .footer-bg {
        background: #172233;
        color: #d7dde7;
        position: relative;
        font-size: 0.9rem;
    }

    .footer-title {
        color: #ffffff; /* Changed from green to white */
        font-size: 1.1rem;
        font-weight: 600;
        margin-bottom: 20px;
    }

    .footer-text {
        line-height: 1.7;
        color: #aeb6c1; /* Light gray matching the image */
    }

    .footer-links {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .footer-links li {
        margin-bottom: 10px;
    }

    .footer-links a {
        color: #aeb6c1;
        text-decoration: none;
        transition: .3s;
    }

    .footer-links a:hover {
        color: #ffffff; /* White on hover instead of green */
        padding-left: 0; /* Removed indent to match standard footer look */
    }

    .footer-contact {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .footer-contact li {
        display: flex;
        align-items: flex-start;
        margin-bottom: 8px;
        color: #aeb6c1;
    }

    /* Removed icon styling since the image does not have icons */
    .footer-contact i {
        display: none; 
    }

    .footer-divider {
        border-color: rgba(255, 255, 255, .1);
        margin: 35px 0 25px;
    }

    .footer-bg small {
        color: #aeb6c1;
    }

    /* Removed green strong tags */
    .footer-bg strong {
        color: #ffffff;
        font-weight: 600;
    }
</style>

<!-- FOOTER -->
<footer class="footer-bg pt-5 pb-3">
    <div class="container">

        <div class="row gy-4">

            <!-- About -->
            <div class="col-lg-3 col-md-6">
                <h5 class="footer-title">Municipality of Placer</h5>

                <p class="footer-text">
                    Your one-stop digital portal for the
                    Municipality of Placer, Surigao del Norte.
                </p>
            </div>

            <!-- Links -->
            <div class="col-lg-3 col-md-6">
                <h5 class="footer-title">Links</h5>

                <ul class="footer-links">
                    <li><a href="index.php">Home</a></li>
                    <li><a href="permit.php">Permits</a></li>
                    <li><a href="about.php">About</a></li>
                    <li><a href="contact.php">Contact</a></li>
                    <li>
                        <a href="generate_user_manual.php" download>
                            <i class="bi bi-file-earmark-pdf"></i> User Manual (PDF)
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Visit or write -->
            <div class="col-lg-3 col-md-6">
                <h5 class="footer-title">Visit or write</h5>

                <ul class="footer-contact">
                    <li>
                        Municipal Hall, Placer, Surigao del Norte
                    </li>
                    <li>
                        bploplacer@gmail.com
                    </li>
                    <li>
                        treasuryofficeplacer@gmail.com
                    </li>
                    <li>
                        Mon–Fri, 8:00 AM–5:00 PM
                    </li>
                </ul>
            </div>

            <!-- Legal -->
            <div class="col-lg-3 col-md-6">
                <h5 class="footer-title">Legal</h5>

                <ul class="footer-links">
                    <li><a href="#">Privacy Policy</a></li>
                    <li><a href="#">Terms of Service</a></li>
                </ul>
            </div>

        </div>

        <hr class="footer-divider">

        <div class="row align-items-center">

            <div class="col-md-5 text-center text-md-start">
                <small>
                    © 2026 Municipality of Placer, Surigao del Norte.
                </small>
            </div>

            <div class="col-md-7 text-center text-md-end mt-3 mt-md-0">
                <small>
                    North Eastern Mindanao State University, Cantilan Campus
                </small>
            </div>

        </div>

    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>