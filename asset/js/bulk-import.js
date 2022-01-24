'use strict';

(function ($) {
    $(document).ready(function() {

        const basePath = window.location.pathname.replace(/\/admin\/.*/, '');
        const uploadUrl = basePath + '/admin/bulk/upload';

        // May avoid crash on big import and small user computer.
        const maxSizeThumbnail = 5000000;
        const maxTotalSizeThumbnails = 200000000;
        const maxCountThumbnails = 200;

        let inputUpload = $($('#media-template-bulk_upload').data('template')).find('input[type=file]');
        const allowedMediaTypes = inputUpload.data('allowed-media-types').split(',');
        const allowedExtensions = inputUpload.data('allowed-extensions').split(',');
        const maxSizeFile = parseInt(inputUpload.data('max-size-file'));
        const maxSizePost = parseInt(inputUpload.data('max-size-post'));

        // Adapted from https://developer.mozilla.org/fr/docs/Web/HTML/Element/Input/file (licence cc0/public domain).
        // Adapted from https://developer.mozilla.org/fr/docs/Web/API/File/Using_files_from_web_applications
        // Add the listener on existing and new media files upload buttons.
        // The new listener is set above to manage dynamically created medias.
        $('#item-media').on('change', '#media-list .media-files-input', function (e) {
            e.preventDefault();
            checkAndSend($(this));
            // Reset the input file to avoid to send files during item post.
            $(this).wrap('<form>').closest('form').get(0).reset();
            $(this).unwrap();
        });

        function checkAndSend(inputUpload) {
            const input = inputUpload[0];
            const inputHidden = input.closest('.media-field-wrapper').getElementsByClassName('filesdata')[0];
            const preview = inputUpload.closest('.media-field-wrapper').find('.media-files-input-preview')[0];
            const mainIndex = /^file\[(\d+)\]\[\]$/g.exec(input.getAttribute('name'))[1];

            while (preview.firstChild) {
                preview.removeChild(preview.firstChild);
            }

            // Prepare hidden input for new files and reset previous data uploaded if any.
            // TODO It may be possible to append new files to the list instead of removing them, but multiple media is already possible in resource form.
            var data = JSON.parse(inputHidden.getAttribute('value'));
            for(var k in data.append) {
                data.remove.push(data.append[k]);
            }
            data.append = {};
            inputHidden.setAttribute('value', JSON.stringify(data));

            /** @var FileList curFiles */
            const curFiles = input.files;
            if (curFiles.length === 0) {
                const para = document.createElement('p');
                para.textContent = inputUpload.data('translate-no-file');
                preview.appendChild(para);
            } else {
                const list = document.createElement('ol');
                var index = 0;
                var total = 0;
                var countThumbnails = 0;
                var totalSizeThumbnails = 0;
                preview.appendChild(list);
                for (const file of curFiles) {
                    ++index;
                    total += file.size;
                    const listItem = document.createElement('li');
                    const div = document.createElement('div');
                    div.classList.add('media-info');
                    if (validateFile(file)) {
                        // Display thumbnail and data.
                        const divImage = document.createElement('div');
                        divImage.classList.add('resource-thumbnail');
                        const image = document.createElement('img');
                        const mainType = file.type.split('/')[0];
                        const subType = file.type.split('/')[1];
                        const newThumbnail = mainType === 'image'
                            && ['avif', 'apng', 'bmp', 'gif', 'ico', 'jpeg', 'png', 'svg', 'webp'].includes(subType)
                            && file.size <= maxSizeThumbnail
                            && countThumbnails <= maxCountThumbnails
                            && totalSizeThumbnails <= maxTotalSizeThumbnails;
                        if (newThumbnail) {
                            countThumbnails++;
                            totalSizeThumbnails += file.size;
                            image.src = URL.createObjectURL(file);
                        } else {
                            image.src = defaultThumbnailUrl(file);
                        }
                        image.onload = function() {
                            URL.revokeObjectURL(this.src);
                        };
                        divImage.appendChild(image);
                        div.appendChild(divImage);
                        const span = document.createElement('span');
                        span.classList.add('resource-name');
                        span.textContent = `${file.name} (${humanFileSize(file.size)})`;
                        div.appendChild(span);
                        listItem.appendChild(div);
                        // Pre-upload file.
                        new FileUpload(image, file, inputHidden, index);
                    } else {
                        const pError = document.createElement('p');
                        pError.textContent = file.name + ': ' + inputUpload.data('translate-invalid-file');
                        pError.classList.add('error');
                        div.appendChild(pError);
                        div.classList.add('messages');
                        listItem.appendChild(div);
                    }
                    list.appendChild(listItem);
                }
                if (total > maxSizePost) {
                    const div = document.createElement('div');
                    const pError = document.createElement('p');
                    pError.textContent = inputUpload.data('translate-max-size-post');
                    pError.classList.add('error');
                    div.appendChild(pError);
                    div.classList.add('messages');
                    preview.prepend(div);
                }
            }
        }

        function validateFile(file) {
            const extension = file.name.slice((file.name.lastIndexOf('.') - 1 >>> 0) + 2);
            return (!allowedMediaTypes.length || allowedMediaTypes.includes(file.type))
                && (!allowedExtensions.length || allowedExtensions.includes(extension.toLowerCase()))
                && file.name.substr(0, 1) !== '.'
                && /^[^{}$?!<>\/\\]+$/.test(file.name)
                && file.size > 0
                && file.size <= maxSizeFile;
        }

        function humanFileSize(number) {
            if (number < 1000) {
                return number + ' bytes';
            } else if (number > 1000 && number < 1000000) {
                return (number / 1000).toFixed(0) + ' KB';
            } else if (number > 1000000) {
                return (number / 1000000).toFixed(0) + ' MB';
            }
        }

        function defaultThumbnailUrl(file) {
            const mainType = file.type.split('/')[0];
            return basePath
                + '/application/asset/thumbnails/'
                + (['audio', 'image', 'video'].includes(mainType) ? mainType : 'default')
                + '.png';
        }

        function FileUpload(img, file, inputHidden, index) {
            const self = this;
            const reader = new FileReader();
            const xhr = new XMLHttpRequest();
            this.xhr = xhr;
            this.ctrl = createThrobber(img);
            xhr.upload
                .addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        const percentage = Math.round((e.loaded * 100) / e.total);
                        self.ctrl.update(percentage);
                    }
                }, false);
            xhr.upload
                .addEventListener('load', function(e) {
                    self.ctrl.update(100);
                    const canvas = self.ctrl.ctx.canvas;
                    // canvas.parentNode.removeChild(canvas);
                    self.ctrl.ctx.fillStyle = 'green';
                }, false);
            xhr.onloadend = function() {
                if (xhr.status == 200) {
                    // Add a hidden input with data.
                    // A check is done to manage a php issue with session_write_close() on some servers.
                    const responseJson = JSON.parse(
                        xhr.response.includes('}<') ? xhr.response.substr(0, xhr.response.indexOf('}<') + 1) : xhr.response
                    );
                    let data = JSON.parse(inputHidden.getAttribute('value'));
                    data.append[index] = responseJson.data.file;
                    inputHidden.setAttribute('value', JSON.stringify(data));
                } else {
                    console.log("error " + this.status);
                }
            }
            xhr.open('POST', uploadUrl);
            xhr.overrideMimeType('text/plain; charset=x-user-defined-binary');
            xhr.setRequestHeader("X-Csrf", $('body.items form.resource-form input[type=hidden][name=csrf]').val());
            xhr.setRequestHeader("X-Filename", file.name);
            reader.onload = function(evt) {
                xhr.send(evt.target.result);
            };
            reader.readAsArrayBuffer(file);
        }

        function createThrobber(img) {
            const throbberWidth = 64;
            const throbberHeight = 6;
            const throbber = document.createElement('canvas');
            throbber.classList.add('upload-progress');
            throbber.setAttribute('width', throbberWidth);
            throbber.setAttribute('height', throbberHeight);
            img.parentNode.appendChild(throbber);
            throbber.ctx = throbber.getContext('2d');
            throbber.ctx.fillStyle = 'orange';
            throbber.update = function(percent) {
                throbber.ctx.fillRect(0, 0, throbberWidth * percent / 100, throbberHeight);
                if (percent === 100) {
                    throbber.ctx.fillStyle = 'green';
                }
            }
            throbber.update(0);
            return throbber;
        }

    });
})(jQuery);
