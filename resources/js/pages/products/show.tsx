import { Head } from '@inertiajs/react';
import { Palette } from 'lucide-react';
import { ArtworkDialog } from '@/components/contract/artwork-dialog';
import { ArtworkGallery } from '@/components/contract/artwork-gallery';
import type { BatchHistory } from '@/components/items/batch-history';
import { ItemDetails, type ItemStock } from '@/components/items/item-details';
import { create as createReceipt } from '@/routes/goods-receipts';
import { PackagingBom } from '@/components/items/packaging-bom';
import { dashboard } from '@/routes';
import { destroy, edit, index, show } from '@/routes/products';
import artworkRoutes from '@/routes/products/artworks';
import type {
    ArtworkRow,
    Item,
    ProductPackagingLine,
    SelectOption,
} from '@/types';

export default function ShowProduct({
    item,
    can,
    stock,
    batches,
    packagingLines,
    packagingOptions,
    artworks,
    artworkKinds,
}: {
    item: Item;
    can: {
        update: boolean;
        delete: boolean;
        receive: boolean;
        view_stock: boolean;
        view_qc: boolean;
    };
    stock: ItemStock | null;
    batches: BatchHistory | null;
    packagingLines: ProductPackagingLine[];
    packagingOptions: SelectOption[];
    artworks: ArtworkRow[];
    artworkKinds: string[];
}) {
    return (
        <>
            <Head title={item.code} />
            <ItemDetails
                item={item}
                can={can}
                editUrl={edit(item.id).url}
                deleteUrl={destroy(item.id).url}
                receiveUrl={createReceipt({ query: { item: item.id } }).url}
                batches={batches}
                stock={stock}
            />
            <div className="space-y-6 px-4 pb-6 sm:px-6">
                <PackagingBom
                    productId={item.id}
                    lines={packagingLines}
                    options={packagingOptions}
                    canEdit={can.update}
                />

                <section className="bg-card rounded-xl border">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                        <div>
                            <h2 className="flex items-center gap-2 font-semibold">
                                <Palette className="size-4" />
                                Artwork
                            </h2>
                            <p className="text-muted-foreground text-sm">
                                How the pack must look. The approved version
                                shows on every batch of this product and on the
                                packing floor.
                            </p>
                        </div>
                        {can.update && (
                            <ArtworkDialog
                                action={artworkRoutes.store(item.id).url}
                                kinds={artworkKinds}
                                party="own"
                            />
                        )}
                    </div>
                    <ArtworkGallery
                        artworks={artworks}
                        canEdit={can.update}
                        statusUrl={(a) =>
                            artworkRoutes.status({
                                product: item.id,
                                artwork: a.id,
                            }).url
                        }
                        destroyUrl={(a) =>
                            artworkRoutes.destroy({
                                product: item.id,
                                artwork: a.id,
                            }).url
                        }
                        className="p-5"
                        emptyText="No artwork yet. Upload the label, tube or carton so the packing line knows what the batch must look like."
                    />
                </section>
            </div>
        </>
    );
}

ShowProduct.layout = ({ item }: { item: Item }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Products', href: index() },
        { title: item.code, href: show(item.id) },
    ],
});
