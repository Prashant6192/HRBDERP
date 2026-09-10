import { SlidersHorizontal } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    DASHBOARD_CARDS,
    type DashboardCardKey,
} from '@/hooks/use-dashboard-cards';

export function CustomiseMenu({
    available,
    visible,
    toggle,
    reset,
}: {
    available: DashboardCardKey[];
    visible: (key: DashboardCardKey) => boolean;
    toggle: (key: DashboardCardKey) => void;
    reset: () => void;
}) {
    const cards = DASHBOARD_CARDS.filter((c) => available.includes(c.key));

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm">
                    <SlidersHorizontal className="size-4" />
                    Customise
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel>Show on my dashboard</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {cards.map((card) => (
                    <DropdownMenuCheckboxItem
                        key={card.key}
                        checked={visible(card.key)}
                        onCheckedChange={() => toggle(card.key)}
                        onSelect={(e) => e.preventDefault()}
                    >
                        {card.label}
                    </DropdownMenuCheckboxItem>
                ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={reset}>
                    Show everything
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
