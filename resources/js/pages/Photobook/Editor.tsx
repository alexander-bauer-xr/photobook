import { Head } from '@inertiajs/react';
import App from '../../../photobook-editor/src/App';
import '../../../photobook-editor/src/index.css';

type EditorProps = {
    hash?: string;
};

export default function Editor({ hash = '' }: EditorProps) {
    return (
        <>
            <Head title="Editor" />
            <App initialAlbumKey={hash} />
        </>
    );
}
