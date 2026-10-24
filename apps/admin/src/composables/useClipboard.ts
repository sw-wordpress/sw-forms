import { useI18n } from 'vue-i18n';
import { useNotify } from './useNotify';

export function useClipboard() {
  const { t } = useI18n();
  const notify = useNotify();
  return async (text: string): Promise<void> => {
    try {
      await navigator.clipboard.writeText(text);
      notify.success(t('common.copied'));
    } catch {
      notify.info(text);
    }
  };
}
