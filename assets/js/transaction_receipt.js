(() => {
    'use strict';

    const button = document.getElementById('download-transaction');
    if (!button) return;
    const message = document.getElementById('transaction-download-message');
    const page = document.querySelector('.transaction-detail-page');
    const text = (root, selector) => (root.querySelector(selector)?.textContent || '').replace(/\s+/g, ' ').trim();

    button.addEventListener('click', async () => {
        button.disabled = true;
        message.textContent = 'Preparing receipt…';
        try {
            // Use only the already-authorized details displayed on this page.
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            if (!ctx) throw new Error('Canvas unavailable');
            const width = 720;
            const ink = '#0d2418';
            const muted = '#718079';
            const left = 64;
            const right = 656;
            const commands = [];
            const font = (size, weight) => `${weight} ${size}px Arial, sans-serif`;
            const rect = (x, y, w, h, color, radius = 0) => commands.push(() => {
                ctx.fillStyle = color;
                ctx.beginPath();
                ctx.roundRect(x, y, w, h, radius);
                ctx.fill();
            });
            const circle = (x, y, radius, color) => commands.push(() => {
                ctx.fillStyle = color;
                ctx.beginPath();
                ctx.arc(x, y, radius, 0, Math.PI * 2);
                ctx.fill();
            });
            const divider = (y, dashed = false) => commands.push(() => {
                ctx.strokeStyle = '#e3eae5';
                ctx.lineWidth = 1;
                ctx.setLineDash(dashed ? [5, 6] : []);
                ctx.beginPath();
                ctx.moveTo(left, y);
                ctx.lineTo(right, y);
                ctx.stroke();
                ctx.setLineDash([]);
            });
            const label = (value, x, y, size = 16, color = ink, weight = '400', align = 'left') => commands.push(() => {
                ctx.font = font(size, weight);
                ctx.fillStyle = color;
                ctx.textAlign = align;
                ctx.fillText(value, x, y);
            });
            // Measure first, then draw at the final height. Long words and
            // references wrap too, keeping the complete record in the image.
            const block = (value, x, y, maxWidth, size = 17, color = ink, weight = '400', align = 'left') => {
                ctx.font = font(size, weight);
                let buffer = '';
                const lines = [];
                for (const word of value.split(/\s+/)) {
                    if (buffer && ctx.measureText(`${buffer} ${word}`).width > maxWidth) {
                        lines.push(buffer);
                        buffer = '';
                    }
                    for (const char of (buffer ? ` ${word}` : word)) {
                        if (buffer && ctx.measureText(buffer + char).width > maxWidth) {
                            lines.push(buffer);
                            buffer = '';
                        }
                        buffer += char;
                    }
                }
                lines.push(buffer);
                lines.forEach((value, index) => label(value, x, y + index * (size + 8), size, color, weight, align));
                return lines.length * (size + 8);
            };

            // Forest-green masthead and a small gold brand mark.
            rect(24, 24, 672, 202, ink, 24);
            rect(24, 150, 672, 76, ink);
            circle(623, 86, 55, '#173a29');
            circle(650, 175, 38, '#204632');
            rect(left, 58, 44, 44, '#f0a500', 14);
            label('G', left + 22, 65, 28, ink, '700', 'center');
            label('GenPay', 121, 62, 30, '#ffffff', '700');
            label('TRANSACTION RECEIPT', left, 132, 13, '#b9cec0', '700');
            label('Your transaction, saved.', left, 158, 24, '#ffffff', '700');

            const statusElement = page.querySelector('.uv-status');
            const status = text(page, '.uv-status') || 'Unknown';
            const success = statusElement?.classList.contains('is-active');
            const pending = statusElement?.classList.contains('is-pending');
            const inactive = statusElement?.classList.contains('is-inactive');
            const statusColor = success ? '#17633c' : pending ? '#98650b' : inactive ? '#9e3d43' : '#647468';
            const statusBg = success ? '#e9f6ee' : pending ? '#fff4dc' : inactive ? '#fcecef' : '#edf3ef';
            let y = 254;
            label('TRANSACTION AMOUNT', width / 2, y, 12, muted, '700', 'center');
            y += 28;
            y += block(text(page, '.uv-amount'), width / 2, y, 552, 48, ink, '700', 'center');
            y += 8;
            y += block(text(page, '.gp-hero-badge'), width / 2, y, 552, 18, muted, '400', 'center');
            y += 14;
            ctx.font = font(14, '700');
            const badgeWidth = Math.min(552, Math.max(128, ctx.measureText(status).width + 56));
            const badgeStart = commands.length;
            const badgeHeight = block(status, width / 2 + 5, y + 10, badgeWidth - 48, 14, statusColor, '700', 'center') + 18;
            const badgeText = commands.splice(badgeStart);
            rect((width - badgeWidth) / 2, y, badgeWidth, badgeHeight, statusBg, 16);
            circle((width - badgeWidth) / 2 + 19, y + 19, 4, statusColor);
            commands.push(...badgeText);
            y += badgeHeight + 30;
            divider(y, true);
            y += 28;
            label('TRANSACTION DETAILS', left, y, 13, '#1e5c3a', '700');
            y += 36;

            page.querySelectorAll('.uv-fields .uv-field').forEach((field) => {
                const fieldName = text(field, '.uv-field-label');
                const fieldValue = text(field, '.uv-field-value');
                const labelHeight = block(fieldName, left, y + 2, 174, 14, muted);
                const valueHeight = block(fieldValue, right, y, 380, 17, ink, '600', 'right');
                y += Math.max(labelHeight, valueHeight) + 18;
                divider(y);
                y += 20;
            });

            const items = page.querySelector('.uv-items');
            if (items) {
                y += 8;
                label('ORDER SUMMARY', left, y, 13, '#1e5c3a', '700');
                y += 36;
                items.querySelectorAll('.uv-item').forEach((item) => {
                    const itemHeight = block(`${text(item, '.uv-item-qty')} ${text(item, '.uv-item-name')}`, left, y, 390, 16);
                    const priceHeight = block(text(item, '.uv-item-total'), right, y, 170, 17, ink, '700', 'right');
                    y += Math.max(itemHeight, priceHeight) + 18;
                });
                const totalStart = commands.length;
                const totalHeight = Math.max(58, block(text(items, '.uv-item-grand'), right - 16, y + 18, 380, 20, ink, '700', 'right') + 30);
                // Background must precede the total text in the draw queue.
                const totalText = commands.splice(totalStart);
                rect(left, y, right - left, totalHeight, '#f1f6f2', 12);
                commands.push(...totalText);
                label('Order total', left + 16, y + 20, 16, ink, '700');
                y += totalHeight + 18;
                const warning = text(items, '.uv-item-warn');
                if (warning) y += block(warning, left, y, right - left, 14, '#98650b') + 16;
            }
            y += 8;
            divider(y, true);
            y += 28;
            label('Keep this receipt for your records.', width / 2, y, 14, muted, '400', 'center');
            y += 28;
            label('GENPAY  /  DIGITAL TRANSACTION RECORD', width / 2, y, 10, '#90a097', '700', 'center');
            const height = y + 64;

            canvas.width = width * 2;
            canvas.height = height * 2;
            ctx.scale(2, 2);
            ctx.fillStyle = '#edf3ef';
            ctx.fillRect(0, 0, width, height);
            ctx.shadowColor = 'rgba(13, 36, 24, 0.08)';
            ctx.shadowBlur = 20;
            ctx.shadowOffsetY = 6;
            ctx.fillStyle = '#ffffff';
            ctx.beginPath();
            ctx.roundRect(24, 24, 672, height - 48, 24);
            ctx.fill();
            ctx.shadowBlur = 0;
            ctx.shadowOffsetY = 0;
            ctx.textBaseline = 'top';
            for (const draw of commands) draw();
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
            if (!blob) throw new Error('Image export failed');
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            const reference = text(page, '.uv-mono').replace(/[^a-zA-Z0-9_-]/g, '-').slice(0, 100) || 'transaction';
            link.href = url;
            link.download = `GenPay-Receipt-${reference}.png`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 60000);
            message.textContent = 'Receipt ready. Check your downloads.';
        } catch (error) {
            message.textContent = 'Unable to download the receipt. Please try again.';
        } finally {
            button.disabled = false;
        }
    });
})();
