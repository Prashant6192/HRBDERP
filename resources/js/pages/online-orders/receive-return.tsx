import { Head, Link } from '@inertiajs/react';
import { Camera, ScanLine, Search } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { BarcodeCamera } from '@/components/barcode-camera';
import {
    RecentReturns,
    ReturnPanel,
    useParcelLookup,
    type Option,
    type RecentReturn,
} from '@/components/online-orders/return-receiver';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { index as onlineOrders } from '@/routes/online-orders';
import {
    create as receiveRoute,
    index as returnsIndex,
} from '@/routes/online-orders/returns';

export default function ReceiveReturn({
    code: presetCode,
    kinds,
    stores,
    recent,
}: {
    code: string;
    kinds: Option[];
    stores: Option[];
    recent: RecentReturn[];
}) {
    const [code, setCode] = useState(presetCode);
    const [camera, setCamera] = useState(false);
    const codeInput = useRef<HTMLInputElement>(null);
    const { find, finding, problem, found, reset } = useParcelLookup();

    useEffect(() => {
        if (presetCode) {
            void find(presetCode);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const onFind = (e: FormEvent) => {
        e.preventDefault();
        void find(code);
    };

    const scanned = (value: string) => {
        setCode(value);
        setCamera(false);
        void find(value);
    };

    const startOver = () => {
        reset();
        setCode('');
        requestAnimationFrame(() => codeInput.current?.focus());
    };

    return (
        <>
            <Head title="Receive a return" />
            <div className="mx-auto w-full max-w-3xl min-w-0 space-y-6 p-4 sm:p-6">
                <PageHeader
                    title="Receive a return"
                    description="Scan the label of the parcel that came back, count what is good and what is damaged, and receive it."
                />

                <section className="bg-card rounded-xl border p-4">
                    <form onSubmit={onFind} className="space-y-2">
                        <Label htmlFor="code">Find the parcel</Label>
                        <div className="flex gap-2">
                            <div className="relative min-w-0 flex-1">
                                <ScanLine className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2" />
                                <Input
                                    id="code"
                                    ref={codeInput}
                                    value={code}
                                    onChange={(e) => setCode(e.target.value)}
                                    placeholder="Scan, or type the AWB or order number"
                                    autoFocus
                                    autoComplete="off"
                                    className="h-12 pl-10 text-base"
                                />
                            </div>
                            <Button
                                type="button"
                                variant={camera ? 'secondary' : 'outline'}
                                className="h-12 px-4"
                                onClick={() => setCamera((on) => !on)}
                                aria-pressed={camera}
                            >
                                <Camera className="size-4" />
                                <span className="hidden sm:inline">Camera</span>
                            </Button>
                            <Button
                                type="submit"
                                className="h-12 px-5"
                                disabled={finding || code.trim() === ''}
                            >
                                <Search className="size-4" />
                                <span className="hidden sm:inline">
                                    {finding ? 'Finding…' : 'Find'}
                                </span>
                            </Button>
                        </div>
                        {camera && (
                            <BarcodeCamera
                                onCode={scanned}
                                busy={finding}
                                onClose={() => setCamera(false)}
                                className="mx-auto max-w-md"
                            />
                        )}
                        {problem && (
                            <p className="rounded-lg border border-red-600/30 bg-red-500/10 p-3 text-sm text-red-800 dark:text-red-200">
                                {problem}
                            </p>
                        )}
                    </form>
                </section>

                {found && (
                    <ReturnPanel
                        key={found.shipment.id}
                        found={found}
                        kinds={kinds}
                        stores={stores}
                        onDone={startOver}
                        onCancel={startOver}
                    />
                )}

                <RecentReturns
                    recent={recent}
                    action={
                        <Link
                            href={returnsIndex()}
                            className="text-primary text-sm font-medium underline-offset-4 hover:underline"
                        >
                            All returns
                        </Link>
                    }
                />
            </div>
        </>
    );
}

ReceiveReturn.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Online orders', href: onlineOrders() },
        { title: 'Returns', href: returnsIndex() },
        { title: 'Receive a return', href: receiveRoute() },
    ],
};
