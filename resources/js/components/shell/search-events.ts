/**
 * Opening the Ctrl K search from anywhere: the header, the column, the
 * phone's search bar. One listener lives in the shell.
 */
const EVENT = 'erp:open-search';

export function openSearch(): void {
    window.dispatchEvent(new Event(EVENT));
}

export function onOpenSearch(handler: () => void): () => void {
    window.addEventListener(EVENT, handler);

    return () => window.removeEventListener(EVENT, handler);
}
