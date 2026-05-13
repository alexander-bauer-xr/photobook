import { Head } from '@inertiajs/react';
import PrintView from '../../../photobook-editor/src/components/PrintView';
import '../../../photobook-editor/src/index.css';

type PrintSettings = {
    bleed_mm?: number;
    crop_marks?: boolean;
    spine_margin_mm?: number;
};

type PrintProps = {
    hash: string;
    printSettings?: PrintSettings;
};

export default function Print({ hash, printSettings }: PrintProps) {
    return (
        <>
            <Head title="Print" />
            <PrintView hash={hash} printSettings={printSettings} />
        </>
    );
}
