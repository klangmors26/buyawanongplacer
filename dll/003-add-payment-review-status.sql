-- Adds the new "payment_review" status (applicant has paid; Treasury must review the
-- payment and click "Proceed to BPLO", which sets status = 'payment_confirmed').
-- RUN THIS ONCE on the live database BEFORE deploying the PHP changes.
ALTER TABLE `business_applications`
  MODIFY `status` ENUM('draft','submitted','pending_payment','payment_review','payment_confirmed',
                       'under_review','approved','claimed','verified','rejected','cancelled','renewed')
  DEFAULT 'draft';
