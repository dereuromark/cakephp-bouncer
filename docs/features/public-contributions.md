# Public contributions

Bouncer captures dirty fields in `beforeSave`. If your table's `beforeSave`
derives fields, such as country from city, attach Bouncer with priority 1 so
the proposal contains only submitted fields:

```php
$this->Articles->addBehavior('Bouncer.Bouncer', ['priority' => 1]);
```

Attach Bouncer in the action that accepts a public proposal. Other actions can keep their normal save behavior. Validate the form and restrict which fields visitors may submit before saving.

```php
public function propose($id)
{
    $this->Articles->addBehavior('Bouncer.Bouncer', [
        'allowGuests' => true,
        'autoSupersede' => false,
    ]);

    $article = $this->Articles->get($id);
    if ($this->request->is(['post', 'put'])) {
        $article = $this->Articles->patchEntity($article, $this->request->getData(), [
            'fields' => ['title', 'body'],
        ]);
        $this->Articles->save($article, [
            'bouncerGuest' => true,
            'bouncerContact' => (string)$this->request->getData('email'),
            'bouncerUserDisplay' => (string)$this->request->getData('name'),
            'bouncerNote' => (string)$this->request->getData('note'),
        ]);
    }

    $this->set(compact('article'));
}
```

`allowGuests` defaults to `false`. A missing user ID is accepted only when the behavior has `allowGuests` enabled and the save or delete has `bouncerGuest => true`. Each guest submission creates a new pending record with a null `user_id`. The contact is optional and limited to 255 characters. Guest proposals never supersede other pending proposals, whatever `autoSupersede` says. Give public tables `autoSupersede => false` too, so a logged-in proposal does not supersede the guest proposals already waiting. The default is `true`.

Rolling the migration back sets `user_id` of guest records to `0`.

Your app must handle captcha and rate limiting. It must also check reviewer access before calling the table API. Reviewers outside the plugin admin UI can use the same methods:

```php
// After your application's authorization check:
$records = $this->fetchTable('Bouncer.BouncerRecords');
$record = $records->get($id);
$records->approve($record, $reviewerId, 'Accepted');
// Or, for another pending record:
$records->reject($record, $reviewerId, 'Reason for rejection');
```

`approve()` applies the proposal and records the review in one transaction. It returns the saved source entity, or `true` for a delete, and returns `false` for a resolved proposal. Failures while applying or recording an approval throw an exception and roll back. `reject()` returns `false` for a resolved proposal or a blank reason.
