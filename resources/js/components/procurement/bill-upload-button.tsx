import { router } from '@inertiajs/react';
import { FileUp } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { intake } from '@/routes/goods-receipts';

/**
 * Upload a supplier's bill from anywhere: it is read, matched to the
 * masters, and the receipt form opens filled in from it.
 */
export function BillUploadButton({
    readerAvailable,
    readsPhotos = true,
    variant = 'outline',
}: {
    readerAvailable: boolean;
    /** Whether a photo or a scan can be read too, or only a PDF from billing software. */
    readsPhotos?: boolean;
    variant?: 'default' | 'outline';
}) {
    const input = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);

    const upload = (file: File | undefined) => {
        if (!file) return;
        setUploading(true);
        router.post(
            intake().url,
            { invoice: file },
            { forceFormData: true, onFinish: () => setUploading(false) },
        );
    };

    return (
        <>
            <input
                ref={input}
                type="file"
                accept={
                    readsPhotos
                        ? 'application/pdf,image/jpeg,image/png,image/webp'
                        : 'application/pdf'
                }
                className="hidden"
                onChange={(e) => {
                    upload(e.target.files?.[0]);
                    e.target.value = '';
                }}
            />
            <Button
                type="button"
                variant={variant}
                disabled={!readerAvailable || uploading}
                onClick={() => input.current?.click()}
                title={
                    !readerAvailable
                        ? 'The bill reader is not set up on this server'
                        : readsPhotos
                          ? "Upload the supplier's bill as a PDF or a photo: it is read and matched to the materials"
                          : "Upload the supplier's bill as the PDF their billing software produced: it is read here and matched to the materials"
                }
            >
                <FileUp className="size-4" />
                {uploading ? 'Reading the bill…' : 'Upload bill'}
            </Button>
        </>
    );
}
