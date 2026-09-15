import './bootstrap';
import { fileUploadHandler } from './upload-progress';
import { pdfDrawSignature, pdfPlacementItem } from './sign-pdf';
import { pdfEditorPreview, computeEffectiveFontSize, isRtlText } from './edit-pdf';

window.fileUploadHandler = fileUploadHandler;
window.pdfDrawSignature = pdfDrawSignature;
window.pdfPlacementItem = pdfPlacementItem;
window.pdfEditorPreview = pdfEditorPreview;
window.computeEffectiveFontSize = computeEffectiveFontSize;
window.isRtlText = isRtlText;

document.addEventListener('alpine:init', () => {
    if (window.Alpine) {
        window.Alpine.data('fileUploadHandler', fileUploadHandler);
        window.Alpine.data('pdfDrawSignature', pdfDrawSignature);
        window.Alpine.data('pdfPlacementItem', pdfPlacementItem);
        window.Alpine.data('pdfEditorPreview', pdfEditorPreview);
    }
});
