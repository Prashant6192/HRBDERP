import { Head } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import { useEffect } from 'react';
import { Scanner } from '@/components/floor/scanner';
import {
    RecentReturns,
    ReturnPanel,
    useParcelLookup,
    type Option,
    type RecentReturn,
} from '@/components/online-orders/return-receiver';

/**
 * Receiving a returned parcel on the floor phone: scan its label, count
 * with the thumb, receive.
 */
export default function FloorReturn({
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
    const { find, finding, problem, found, reset } = useParcelLookup();

    useEffect(() => {
        if (presetCode) {
            void find(presetCode);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const startOver = () => {
        reset();
        window.scrollTo({ top: 0 });
    };

    return (
        <>
            <Head title="Receive a return" />
            <div className="space-y-4">
                <div className="flex items-center gap-3">
                    <span className="bg-primary/10 text-primary flex size-10 items-center justify-center rounded-xl">
                        <Undo2 className="size-5" />
                    </span>
                    <div>
                        <h1 className="text-lg leading-tight font-semibold">
                            Receive a return
                        </h1>
                        <p className="text-muted-foreground text-xs">
                            Scan the returning packet&rsquo;s label, then count
                            what came back.
                        </p>
                    </div>
                </div>

                {found ? (
                    <ReturnPanel
                        key={found.shipment.id}
                        found={found}
                        kinds={kinds}
                        stores={stores}
                        floor
                        onDone={startOver}
                        onCancel={startOver}
                    />
                ) : (
                    <>
                        <Scanner
                            onCode={(c) => void find(c)}
                            busy={finding}
                            placeholder="Scan the label's barcode…"
                        />
                        {problem && (
                            <p
                                role="alert"
                                className="rounded-2xl border border-red-600/30 bg-red-500/10 p-4 text-sm font-medium text-red-800 dark:text-red-200"
                            >
                                {problem}
                            </p>
                        )}
                        <RecentReturns recent={recent} floor />
                    </>
                )}
            </div>
        </>
    );
}
