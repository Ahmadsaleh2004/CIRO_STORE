<?php
/**
 * app/views/admin/notify-modal.php
 * A shared modal for sending a direct message — parameterised by target type (admin
 * today, user later from this same file). Opened from JavaScript:
 * openNotifyModal('admin', id, name).
 */
?>
<div class="modal fade" id="notifyModal" tabindex="-1"
     aria-labelledby="notifyModalLabel" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header u-border-section">
                <h5 class="modal-title" id="notifyModalLabel">
                    🔔 Message to <span id="notifyTargetName"
                                        class="u-accent"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">

                <div class="float-group mb-3">
                    <input type="text"
                           id="notifyTitleInput"
                           placeholder=" ">
                    <label>Message Title <span class="text-danger">*</span></label>
                </div>

                <div class="float-group mb-3">
                    <textarea id="notifyBodyInput"
                              rows="4"
                              placeholder=" "></textarea>
                    <label>Message Body <span class="text-danger">*</span></label>
                </div>

                <button id="notifySendBtn"
                        class="btn btn-success w-100 btn-disabled-faded"
                        disabled>
                    Send Message
                </button>

            </div>

        </div>
    </div>
</div>

<?php // Hidden inputs carrying the target type and id through to JavaScript ?>
<input type="hidden" id="notifyTargetType" value="">
<input type="hidden" id="notifyTargetId"   value="">
