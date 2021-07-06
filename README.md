# QR Code

A REDCap external module providing an action tag that will generate a QR code when a form is saved.

## Installation

- Clone this repo into `<redcap-root>/modules/redcap_qrcode_<version-number>`, or
- Obtain this module from the Consortium [REDCap Repo](https://redcap.vanderbilt.edu/consortium/modules/index.php) via the Control Center.
- Go to _Control Center > Technical / Developer Tools > External Modules_ and enable **QR Code**.

## Requirements

- REDCAP 10.1.0 or newer (tested on REDCap 11.1.3)

## Configuration

- There are no module-specific system or project configuration options.
- All action is controlled by the .... action tag ;)

## Action Tag

`@QRCODE="source_field"`

Apply this action tag to a fields of type **Text Box** (no validation) or **File Upload** only, and specifiy the name of the source field (which needs to be on the same form).

The value of *source_field* will be converted to a QR code and stored in the field with the action tag. The storage format will depend on the field type:

- **PNG file** stored in *edocs* when used on a *File Upload* field
- **Base64**-encoded string when used on a *Text Box* field. This could then be used to produce inline-images with `<img src="data:image/png;base64, [field_with_qrcode]">`

## Use case

- You might want to include a QR code in an email or notification, or to display it on a data entry form or survey. This module will allow you to do this.

## Notes & Limitations

- This action tag is applied **on saving** only. Do not expect it to work in real time (i.e. in the browser, with live updates as with piping).
- However, the string value, once existing, can be piped freely. Thus, there should virtually no limits in displaying the QR code anywhere (i.e. on different instruments, in ASIs or Alerts & Notifications).
- One exception though: there is no way to get a QR code onto the *first* page of a public survey. No can do. Sorry. Simply use the 'one section per page' option.
- Text box fields must not have any validation.
- The *source_field_name* field **must** be on the same instrument. Specify the field name only, no square brackets (this is not piping).
- The QR code will be generated each time (i.e. it will be overwritten if the source value changes; `@READONLY` is **not** honored).
- When using a file upload field as destination, previous versions will **not** be kept. Each new version will generate a new file version (if file versioning is on) but automatically delete the previous version. The module is smart enough to not overwrite identical QR codes, though. This is to prevent unnecessary clutter. Also, changes of the source field for the QR code will be logged anyway, so there is no need to have this for derived data.
- Note, each QR code generation will create a temporary file, and in case of a file upload field as destination, will copy the previously stored file to the temp folder for inspection. Thus, this module might seriously slow down your server in high throughput situations.
- On data entry forms and surveys, the "Upload file" / "Upload new version" links are removed for file upload file upload fields with @QRCODE.

## Tips & Tricks

- *Alerts & Notifications*, as well as *ASIs*, **force** you to use the Rich Text Editor, which can be a major pain sometimes (read: often). As when you would like to include a barcode. To do so, you have to jump through hoops.  
  So, normally you would expect to go into source view, and simply enter the image tag shown above. This works. Kinda. But only the first time you save the Alert / ASI, because on load, the Rich Text Editor thinks it is smart (when it is not) and removes the `src` tag of your image. Nice. Thank you very much.  
  So, here's how you do it:  
  - Create a helper text field and use a calctext expression similar to this one:  
  `@CALCTEXT(concat('<img src="data:image/png;base64, ', [text_qr], '">'))`  
  where `text_qr` is your field with `@QRCODE`.
  - Then, pipe the field with the above calctext into the email message. Thankfully, Rich Text Editor will leave it alone then.

## Changelog

Version | Description
------- | ------------------
v1.0.0  | Initial release.
