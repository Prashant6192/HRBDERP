/**
 * Printing straight to a label printer from the browser.
 *
 * TSC printers take a plain-text program (TSPL). Chrome and Edge can hand
 * it to a printer on a USB cable (WebUSB) or over Bluetooth Low Energy
 * (Web Bluetooth); the person picks the printer once and the browser
 * remembers it. Where neither is possible — Safari, Firefox, an iPhone —
 * the 100 × 150 mm PDF prints through the printer's own driver instead.
 */

type UsbEndpoint = {
    endpointNumber: number;
    direction: 'in' | 'out';
    type: string;
};
type UsbAlternate = { endpoints: UsbEndpoint[] };
type UsbInterface = {
    interfaceNumber: number;
    alternates: UsbAlternate[];
    claimed: boolean;
};
type UsbConfiguration = { interfaces: UsbInterface[] };

export type UsbDeviceLike = {
    productName?: string;
    manufacturerName?: string;
    serialNumber?: string;
    vendorId: number;
    productId: number;
    opened: boolean;
    configuration: UsbConfiguration | null;
    open(): Promise<void>;
    selectConfiguration(n: number): Promise<void>;
    claimInterface(n: number): Promise<void>;
    transferOut(endpoint: number, data: BufferSource): Promise<unknown>;
};

type UsbApi = {
    getDevices(): Promise<UsbDeviceLike[]>;
    requestDevice(options: {
        filters: { vendorId?: number; classCode?: number }[];
    }): Promise<UsbDeviceLike>;
};

type BleCharacteristic = {
    properties: { write: boolean; writeWithoutResponse: boolean };
    writeValue(data: BufferSource): Promise<void>;
    writeValueWithoutResponse?(data: BufferSource): Promise<void>;
};
type BleService = { getCharacteristics(): Promise<BleCharacteristic[]> };
type BleServer = {
    connected: boolean;
    getPrimaryServices(): Promise<BleService[]>;
};
export type BleDeviceLike = {
    id: string;
    name?: string;
    gatt?: { connected: boolean; connect(): Promise<BleServer> };
};
type BleApi = {
    requestDevice(options: {
        acceptAllDevices: boolean;
        optionalServices: string[];
    }): Promise<BleDeviceLike>;
};

/** TSC Auto ID Technology's USB vendor id. */
const TSC_VENDOR = 0x1203;
/** USB class code for printers. */
const PRINTER_CLASS = 7;

/** Services that label printers expose their serial pipe on over BLE. */
const BLE_SERVICES = [
    '000018f0-0000-1000-8000-00805f9b34fb',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '0000ff00-0000-1000-8000-00805f9b34fb',
    '0000fee7-0000-1000-8000-00805f9b34fb',
];

const usb = (): UsbApi | null =>
    typeof navigator !== 'undefined' && 'usb' in navigator
        ? ((navigator as unknown as { usb: UsbApi }).usb ?? null)
        : null;

const bluetooth = (): BleApi | null =>
    typeof navigator !== 'undefined' && 'bluetooth' in navigator
        ? ((navigator as unknown as { bluetooth: BleApi }).bluetooth ?? null)
        : null;

export const supportsUsb = (): boolean => usb() !== null;
export const supportsBluetooth = (): boolean => bluetooth() !== null;

export function usbName(device: UsbDeviceLike): string {
    return (
        [device.manufacturerName, device.productName]
            .filter(Boolean)
            .join(' ') ||
        `USB printer ${device.vendorId.toString(16)}:${device.productId.toString(16)}`
    );
}

/** USB printers this browser has already been allowed to use. */
export async function pairedUsbPrinters(): Promise<UsbDeviceLike[]> {
    const api = usb();

    if (!api) return [];

    try {
        return await api.getDevices();
    } catch {
        return [];
    }
}

/** Ask the person to pick a USB printer; the browser remembers the choice. */
export async function requestUsbPrinter(): Promise<UsbDeviceLike> {
    const api = usb();

    if (!api) {
        throw new Error(
            'This browser cannot reach a USB printer. Use Chrome or Edge on a computer or Android phone.',
        );
    }

    return api.requestDevice({
        filters: [{ vendorId: TSC_VENDOR }, { classCode: PRINTER_CLASS }],
    });
}

export async function requestBluetoothPrinter(): Promise<BleDeviceLike> {
    const api = bluetooth();

    if (!api) {
        throw new Error(
            'This browser cannot reach a Bluetooth printer. Use Chrome or Edge.',
        );
    }

    return api.requestDevice({
        acceptAllDevices: true,
        optionalServices: BLE_SERVICES,
    });
}

const CHUNK_USB = 16 * 1024;
const CHUNK_BLE = 180;

export async function sendUsb(
    device: UsbDeviceLike,
    data: Uint8Array,
): Promise<void> {
    try {
        if (!device.opened) await device.open();
        if (device.configuration === null) await device.selectConfiguration(1);
    } catch {
        throw new Error(
            'The printer could not be opened. On Windows its own driver holds the USB port: print the 100 × 150 PDF through the driver instead, or ask for the port to be switched to WinUSB.',
        );
    }

    const iface = device.configuration?.interfaces.find((i) =>
        i.alternates.some((a) =>
            a.endpoints.some((e) => e.direction === 'out'),
        ),
    );
    const endpoint = iface?.alternates
        .flatMap((a) => a.endpoints)
        .find((e) => e.direction === 'out');

    if (!iface || !endpoint) {
        throw new Error('This USB device has no way to send it a label.');
    }

    if (!iface.claimed) {
        try {
            await device.claimInterface(iface.interfaceNumber);
        } catch {
            throw new Error(
                'Another program is using the printer. Close it (or the TSC driver queue) and try again, or print the 100 × 150 PDF.',
            );
        }
    }

    for (let i = 0; i < data.length; i += CHUNK_USB) {
        await device.transferOut(
            endpoint.endpointNumber,
            data.slice(i, i + CHUNK_USB),
        );
    }
}

export async function sendBluetooth(
    device: BleDeviceLike,
    data: Uint8Array,
): Promise<void> {
    if (!device.gatt) throw new Error('This Bluetooth device cannot print.');

    const server = await device.gatt.connect();
    let target: BleCharacteristic | null = null;

    for (const service of await server.getPrimaryServices()) {
        for (const c of await service.getCharacteristics()) {
            if (c.properties.write || c.properties.writeWithoutResponse) {
                target = c;
                break;
            }
        }

        if (target) break;
    }

    if (!target) {
        throw new Error(
            'This printer does not accept labels over Bluetooth Low Energy. Use its USB cable, or the 100 × 150 PDF.',
        );
    }

    for (let i = 0; i < data.length; i += CHUNK_BLE) {
        const chunk = data.slice(i, i + CHUNK_BLE);

        if (
            target.properties.writeWithoutResponse &&
            target.writeValueWithoutResponse
        ) {
            await target.writeValueWithoutResponse(chunk);
        } else {
            await target.writeValue(chunk);
        }
    }
}
