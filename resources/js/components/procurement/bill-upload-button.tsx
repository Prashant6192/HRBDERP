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
    variant = 'outline',
}: {
    readerAvailable: boolean;
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
                accept="application/pdf,image/jpeg,image/png,image/webp"
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
                    readerAvailable
                        ? "Upload the supplier's bill: it is read and matched to the materials"
                        : 'The bill reader is not set up on this server (no Claude API key)'
                }
            >
                <FileUp className="size-4" />
                {uploading ? 'Reading the bill…' : 'Upload bill'}
            </Button>
        </>
    );
}
