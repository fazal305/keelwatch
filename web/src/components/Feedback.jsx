import { Icon } from './Icon.jsx';

export function Panel({ title, meta, actions, children, className = '', as: Tag = 'section', ...rest }) {
  return (
    <Tag className={`panel ${className}`} {...rest}>
      {(title || actions) && (
        <header className="panel__header">
          <div className="panel__heading">
            {title && <h2 className="panel__title">{title}</h2>}
            {meta && <div className="panel__meta">{meta}</div>}
          </div>
          {actions && <div className="panel__actions">{actions}</div>}
        </header>
      )}
      <div className="panel__body">{children}</div>
    </Tag>
  );
}

export function InlineError({ title, message, correlationId, onRetry, retrying = false }) {
  return (
    <div className="inline-error" role="alert">
      <div className="inline-error__text">
        <p className="inline-error__title">{title}</p>
        {message && <p className="inline-error__message">{message}</p>}
        {correlationId && (
          <p className="inline-error__ref">
            Reference <code>{correlationId}</code>
          </p>
        )}
      </div>
      {onRetry && (
        <button type="button" className="button" onClick={onRetry} disabled={retrying}>
          <Icon name="refresh" size={16} />
          {retrying ? 'Retrying…' : 'Retry'}
        </button>
      )}
    </div>
  );
}

export function EmptyState({ title, children }) {
  return (
    <div className="empty-state">
      <p className="empty-state__title">{title}</p>
      {children && <div className="empty-state__body">{children}</div>}
    </div>
  );
}
