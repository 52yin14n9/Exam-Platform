document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
      const value = document.querySelector(button.dataset.copy)?.textContent?.trim() || '';
      await navigator.clipboard.writeText(value);
      const old = button.textContent; button.textContent = '已复制'; setTimeout(() => button.textContent = old, 1400);
    });
  });

  document.querySelectorAll('[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => { if (!confirm(form.dataset.confirm)) event.preventDefault(); });
  });
  document.querySelectorAll('[data-confirm-button]').forEach((button) => {
    button.addEventListener('click', (event) => { if (!confirm(button.dataset.confirmButton)) event.preventDefault(); });
  });

  document.querySelectorAll('[data-toggle-target]').forEach((control) => {
    const update = () => {
      const target = document.querySelector(control.dataset.toggleTarget);
      if (target) target.hidden = control.value !== control.dataset.toggleValue;
    };
    control.addEventListener('change', update); update();
  });

  const examForm = document.querySelector('[data-autosave]');
  if (examForm) {
    let dirty = false; let saving = false;
    examForm.addEventListener('input', () => { dirty = true; });
    const save = async () => {
      if (!dirty || saving) return;
      dirty = false; saving = true;
      const status = document.querySelector('[data-save-status]');
      if (status) status.textContent = '正在保存…';
      try {
        const response = await fetch(examForm.action, {method: 'POST', body: new FormData(examForm), headers: {'X-Requested-With': 'fetch'}});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || '保存失败');
        if (status) status.textContent = `已保存 ${result.saved_at}`;
      } catch (error) {
        dirty = true; if (status) status.textContent = error.message || '保存失败，请检查网络';
      } finally { saving = false; }
    };
    setInterval(save, 15000);
    document.querySelectorAll('[data-save-now]').forEach(btn => btn.addEventListener('click', save));
    window.addEventListener('beforeunload', (event) => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  }

  document.querySelectorAll('[data-upload-question]').forEach((input) => {
    input.addEventListener('change', async () => {
      if (!input.files?.length) return;
      const form = input.closest('form');
      const target = form?.querySelector('[name="upload_question_id"]');
      if (!form || !target) return;
      target.value = input.dataset.uploadQuestion;
      const payload = new FormData();
      payload.append('_csrf', form.querySelector('[name="_csrf"]')?.value || '');
      payload.append('upload_question_id', input.dataset.uploadQuestion);
      payload.append('screenshot', input.files[0]);
      form.querySelectorAll('textarea[name^="answers["]').forEach((field) => payload.append(field.name, field.value));
      const status = document.querySelector('[data-save-status]');
      if (status) status.textContent = '正在上传并保存…';
      try {
        const response = await fetch(form.action, {method: 'POST', body: payload, headers: {'X-Requested-With': 'fetch'}});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || '上传失败');
        location.reload();
      } catch (error) {
        input.value = '';
        if (status) status.textContent = error.message || '上传失败';
      }
    });
  });
});
